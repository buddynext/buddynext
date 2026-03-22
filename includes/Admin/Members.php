<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName, WordPress.Files.FileName.NotHyphenatedLowercase
/**
 * BuddyNext admin members panel.
 *
 * Provides a submenu page under the BuddyNext top-level menu for
 * listing, suspending, unsuspending, and exporting community members,
 * managing profile field groups and fields, and editing individual
 * member profiles.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Admin panel for managing BuddyNext community members.
 */
class Members extends AdminPageBase {

	/**
	 * Default items per page for member listing.
	 */
private const DEFAULT_PER_PAGE = 20;

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

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
public function register(): void {
	add_action( 'admin_menu', array( $this, 'add_submenu' ) );
	add_action( 'admin_post_bn_suspend_member', array( $this, 'handle_suspend' ) );
	add_action( 'admin_post_bn_unsuspend_member', array( $this, 'handle_unsuspend' ) );
	add_action( 'admin_post_bn_export_members', array( $this, 'handle_export' ) );
	add_action( 'admin_post_bn_create_profile_group', array( $this, 'handle_create_group' ) );
	add_action( 'admin_post_bn_create_profile_field', array( $this, 'handle_create_field' ) );
	add_action( 'admin_post_bn_delete_profile_group', array( $this, 'handle_delete_group' ) );
	add_action( 'admin_post_bn_delete_profile_field', array( $this, 'handle_delete_field' ) );
	add_action( 'admin_post_bn_update_profile_group', array( $this, 'handle_update_group' ) );
	add_action( 'admin_post_bn_update_profile_field', array( $this, 'handle_update_field' ) );
	add_action( 'admin_post_bn_reorder_group', array( $this, 'handle_reorder_group' ) );
	add_action( 'admin_post_bn_reorder_field', array( $this, 'handle_reorder_field' ) );
	add_action( 'admin_post_bn_save_member_profile', array( $this, 'handle_save_member_profile' ) );
	add_action( 'admin_post_bn_edit_profile_field', array( $this, 'handle_edit_field' ) );
	add_action( 'wp_login', array( $this, 'handle_last_login' ), 10, 2 );
}

	/**
	 * Store the last login timestamp for a user on every successful login.
	 *
	 * @param string   $user_login Username.
	 * @param \WP_User $user       WP_User object.
	 * @return void
	 */
public function handle_last_login( string $user_login, \WP_User $user ): void {
	update_user_meta( $user->ID, 'bn_last_login', time() );
}

	/**
	 * Add the Members submenu under the BuddyNext top-level menu.
	 *
	 * @return void
	 */
public function add_submenu(): void {
	add_submenu_page(
		'buddynext',
		__( 'Members', 'buddynext' ),
		__( 'Members', 'buddynext' ),
		'manage_options',
		'buddynext-members',
		array( $this, 'render_page' )
	);
}

	// ── Query ──────────────────────────────────────────────────────────────────

	/**
	 * Return a paginated list of members.
	 *
	 * Accepted args:
	 *   'page'     int    Current page number (1-based). Default 1.
	 *   'per_page' int    Items per page. Default 20.
	 *   'search'   string Optional search string matched against login/email.
	 *   'status'   string 'active' | 'suspended' | 'all'. Default 'all'.
	 *   'orderby'  string User field to order by. Default 'registered'.
	 *   'order'    string 'ASC' | 'DESC'. Default 'DESC'.
	 *   'role'     string WP role slug to filter by. Default '' (all roles).
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{ members: array<int, array<string, mixed>>, total: int, pages: int }
	 */
public function list_members( array $args = array() ): array {
	$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
	$per_page = max( 1, (int) ( $args['per_page'] ?? self::DEFAULT_PER_PAGE ) );
	$search   = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
	$status   = sanitize_key( (string) ( $args['status'] ?? 'all' ) );
	$orderby  = sanitize_key( (string) ( $args['orderby'] ?? 'registered' ) );
	$order    = strtoupper( sanitize_text_field( (string) ( $args['order'] ?? 'DESC' ) ) );
	$order    = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';

	$query_args = array(
		'number'  => $per_page,
		'offset'  => ( $page - 1 ) * $per_page,
		'orderby' => $orderby,
		'order'   => $order,
		'fields'  => 'all',
	);

	if ( '' !== $search ) {
		$query_args['search']         = '*' . $search . '*';
		$query_args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
	}

	if ( '' !== ( $args['role'] ?? '' ) ) {
		$query_args['role'] = sanitize_key( (string) $args['role'] );
	}

	if ( 'suspended' === $status ) {
		global $wpdb;

		// Fetch IDs of currently suspended users from the authoritative table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$suspended_ids = $wpdb->get_col(
			"SELECT DISTINCT user_id
				 FROM {$wpdb->prefix}bn_user_suspensions
				 WHERE lifted_at IS NULL
				   AND (expires_at IS NULL OR expires_at > NOW())"
		);

		if ( empty( $suspended_ids ) ) {
			return array(
				'members' => array(),
				'total'   => 0,
				'pages'   => 1,
			);
		}

		$query_args['include'] = array_map( 'absint', $suspended_ids );
	}

	$user_query = new \WP_User_Query( $query_args );

	$result_ids = wp_list_pluck( $user_query->get_results(), 'ID' );

	// Pre-fetch suspended status for every user in this page in one query
	// so the foreach below never issues a per-row DB round-trip.
	$suspended_set = array();
	if ( ! empty( $result_ids ) ) {
		global $wpdb;
		$int_ids      = array_map( 'intval', $result_ids );
		$placeholders = implode( ',', array_fill( 0, count( $int_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$suspended_set = array_flip(
			(array) $wpdb->get_col(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->prefix}bn_user_suspensions WHERE user_id IN ({$placeholders}) AND lifted_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					...$int_ids
				)
			)
		);

		// Prime usermeta cache for this batch — prevents extra queries during
		// avatar rendering and any meta reads that follow in template loops.
		update_meta_cache( 'user', $result_ids );
	}

	$members = array();
	foreach ( $user_query->get_results() as $user ) {
		$members[] = array(
			'id'          => $user->ID,
			'login'       => $user->user_login,
			'email'       => $user->user_email,
			'display'     => $user->display_name,
			'registered'  => $user->user_registered,
			'suspended'   => isset( $suspended_set[ $user->ID ] ),
			'role'        => ( (array) $user->roles )[0] ?? 'subscriber',
			'last_active' => (int) get_user_meta( $user->ID, 'bn_last_active', true ),
			'last_login'  => (int) get_user_meta( $user->ID, 'bn_last_login', true ),
			'post_count'  => 0,
		);
	}

	$total = (int) $user_query->get_total();
	$pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

	return compact( 'members', 'total', 'pages' );
}

	/**
	 * Return the total number of registered users.
	 *
	 * @return int
	 */
public function get_member_count(): int {
	$counts = count_users();
	return (int) ( $counts['total_users'] ?? 0 );
}

	/**
	 * Count users registered in the last 7 days.
	 *
	 * @return int
	 */
private function get_new_this_week_count(): int {
	$q = new \WP_User_Query(
		array(
			'date_query'  => array(
				array(
					'after'     => '7 days ago',
					'inclusive' => true,
				),
			),
			'count_total' => true,
			'number'      => 0,
			'fields'      => 'ID',
		)
	);
	return (int) $q->get_total();
}

	// ── Moderation ─────────────────────────────────────────────────────────────

	/**
	 * Suspend a community member.
	 *
	 * Delegates to ModerationService so the suspension is recorded in
	 * bn_user_suspensions — the single source of truth checked by is_suspended().
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
public function suspend_member( int $user_id ): void {
	buddynext_service( 'moderation' )->suspend_user( $user_id, get_current_user_id() );
}

	/**
	 * Lift the suspension for a community member.
	 *
	 * Delegates to ModerationService so the bn_user_suspensions row is updated
	 * and all systems that call is_suspended() reflect the change immediately.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
public function unsuspend_member( int $user_id ): void {
	buddynext_service( 'moderation' )->unsuspend_user( $user_id, get_current_user_id() );
}

	// ── Admin-post handlers ────────────────────────────────────────────────────

	/**
	 * Handle admin_post_bn_suspend_member form submission.
	 *
	 * @return void
	 */
public function handle_suspend(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
	}

	check_admin_referer( 'bn_suspend_member' );

	$user_id = absint( wp_unslash( $_POST['user_id'] ?? 0 ) );
	if ( $user_id > 0 ) {
		$this->suspend_member( $user_id );
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'    => 'buddynext-members',
				'action'  => 'suspended',
				'user_id' => $user_id,
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

	/**
	 * Handle admin_post_bn_unsuspend_member form submission.
	 *
	 * @return void
	 */
public function handle_unsuspend(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
	}

	check_admin_referer( 'bn_unsuspend_member' );

	$user_id = absint( wp_unslash( $_POST['user_id'] ?? 0 ) );
	if ( $user_id > 0 ) {
		$this->unsuspend_member( $user_id );
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'    => 'buddynext-members',
				'action'  => 'unsuspended',
				'user_id' => $user_id,
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

	/**
	 * Handle admin_post_bn_export_members form submission.
	 *
	 * Sends a CSV file download of all members.
	 *
	 * @return void
	 */
public function handle_export(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
	}

	check_admin_referer( 'bn_export_members' );

	$filename = 'buddynext-members-' . gmdate( 'Y-m-d' ) . '.csv';

	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );

	$this->export_members_csv();
	exit;
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
	 * Handle admin_post_bn_save_member_profile form submission.
	 *
	 * @return void
	 */
public function handle_save_member_profile(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
	}

	check_admin_referer( 'bn_save_member_profile' );

	$user_id = absint( wp_unslash( $_POST['user_id'] ?? 0 ) );
	$wp_user = $user_id > 0 ? get_userdata( $user_id ) : false;

	if ( $user_id <= 0 || ! $wp_user ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'buddynext-members',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	$redirect_url = add_query_arg(
		array(
			'page'    => 'buddynext-members',
			'view'    => 'edit-member',
			'user_id' => $user_id,
		),
		admin_url( 'admin.php' )
	);

	// Handle avatar removal.
	if ( ! empty( $_POST['bn_remove_avatar'] ) ) {
		delete_user_meta( $user_id, 'bn_avatar' );
		\BuddyNext\Core\Plugin::bust_avatar_cache( $user_id );
		wp_cache_delete( "profile_{$user_id}_viewer_owner", 'buddynext_profiles' );
		wp_cache_delete( "profile_{$user_id}_viewer_follower", 'buddynext_profiles' );
		wp_cache_delete( "profile_{$user_id}_viewer_public", 'buddynext_profiles' );
	}

	// Handle avatar upload.
	if ( ! empty( $_FILES['bn_avatar']['name'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- isset checked via !empty on ['name']
		if ( isset( $_FILES['bn_avatar']['size'] ) && $_FILES['bn_avatar']['size'] > 2 * 1024 * 1024 ) {
			wp_safe_redirect( add_query_arg( 'bn_error', 'avatar_size', $redirect_url ) );
			exit;
		}

		$allowed_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- type validated against allowlist
		if ( isset( $_FILES['bn_avatar']['type'] ) && ! in_array( $_FILES['bn_avatar']['type'], $allowed_types, true ) ) {
			wp_safe_redirect( add_query_arg( 'bn_error', 'avatar_type', $redirect_url ) );
			exit;
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$overrides = array( 'test_form' => false );
		$uploaded  = wp_handle_upload( $_FILES['bn_avatar'], $overrides );

		if ( isset( $uploaded['url'] ) && ! isset( $uploaded['error'] ) ) {
			update_user_meta( $user_id, 'bn_avatar', esc_url_raw( $uploaded['url'] ) );
			\BuddyNext\Core\Plugin::bust_avatar_cache( $user_id );
			wp_cache_delete( "profile_{$user_id}_viewer_owner", 'buddynext_profiles' );
			wp_cache_delete( "profile_{$user_id}_viewer_follower", 'buddynext_profiles' );
			wp_cache_delete( "profile_{$user_id}_viewer_public", 'buddynext_profiles' );
		}
	}

	// Handle display name update.
	if ( isset( $_POST['display_name'] ) && '' !== $_POST['display_name'] ) {
		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => sanitize_text_field( wp_unslash( $_POST['display_name'] ) ),
			)
		);
	}

	// Handle email update.
	if ( isset( $_POST['bn_user_email'] ) && '' !== $_POST['bn_user_email'] ) {
		$new_email = sanitize_email( wp_unslash( $_POST['bn_user_email'] ) );
		if ( is_email( $new_email ) && $new_email !== $wp_user->user_email ) {
			$existing_owner = email_exists( $new_email );
			if ( false === $existing_owner || (int) $existing_owner === $user_id ) {
				wp_update_user(
					array(
						'ID'         => $user_id,
						'user_email' => $new_email,
					)
				);
			}
		}
	}

	// Handle role update.
	if ( isset( $_POST['bn_user_role'] ) && '' !== $_POST['bn_user_role'] ) {
		$new_role    = sanitize_key( wp_unslash( $_POST['bn_user_role'] ) );
		$valid_roles = array_keys( wp_roles()->get_names() );
		if ( in_array( $new_role, $valid_roles, true ) ) {
			$user_obj = new \WP_User( $user_id );
			$user_obj->set_role( $new_role );
		}
	}

	// Handle profile slug update.
	if ( isset( $_POST['bn_profile_slug'] ) && '' !== $_POST['bn_profile_slug'] ) {
		$new_slug = sanitize_title( wp_unslash( $_POST['bn_profile_slug'] ) );
		if ( '' !== $new_slug ) {
			if ( \BuddyNext\Core\PageRouter::is_slug_available( $new_slug, $user_id ) ) {
				update_user_meta( $user_id, 'bn_profile_slug', $new_slug );
			} else {
				wp_safe_redirect( add_query_arg( 'bn_error', 'slug_taken', $redirect_url ) );
				exit;
			}
		}
	}

	$profile_data = array();

	// Build a whitelist of known field keys to avoid mass-assignment.
	$known_groups = buddynext_service( 'profiles' )->get_fields();

	foreach ( $known_groups as $group ) {
		$group_key = $group['group_key'];

		if ( 'repeater' === $group['type'] ) {
			// Repeater entries arrive as group_key[n][field_key].
			if ( isset( $_POST[ $group_key ] ) && is_array( $_POST[ $group_key ] ) ) {
				$entries      = array();
				$raw_repeater = wp_unslash( $_POST[ $group_key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				foreach ( (array) $raw_repeater as $entry_idx => $entry_data ) {
					if ( ! is_array( $entry_data ) ) {
						continue;
					}
					$sanitized_entry = array();
					foreach ( $group['fields'] as $field_def ) {
						$fk = $field_def['field_key'];
						if ( isset( $entry_data[ $fk ] ) ) {
							$sanitized_entry[ $fk ] = sanitize_textarea_field( (string) $entry_data[ $fk ] );
						}
					}
					if ( ! empty( $sanitized_entry ) ) {
						$entries[ (int) $entry_idx ] = $sanitized_entry;
					}
				}
				if ( ! empty( $entries ) ) {
					$profile_data[ $group_key ] = $entries;
				}
			}
			continue;
		}

		// Flat group — fields keyed directly by field_key.
		foreach ( $group['fields'] as $field_def ) {
			$fk = $field_def['field_key'];
			if ( isset( $_POST[ $fk ] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized immediately below
				$raw_val = wp_unslash( $_POST[ $fk ] );
				if ( is_array( $raw_val ) ) {
					$profile_data[ $fk ] = array_map( 'sanitize_text_field', $raw_val );
				} else {
					$profile_data[ $fk ] = sanitize_textarea_field( (string) $raw_val );
				}
			}
		}
	}

	if ( ! empty( $profile_data ) ) {
		buddynext_service( 'profiles' )->save_profile( $user_id, $profile_data );
	}

	/**
	 * Fires after BuddyNext has finished saving a member's profile from the admin.
	 * Use this to save additional custom field values.
	 *
	 * @param int     $user_id   User ID that was saved.
	 * @param WP_User $wp_user   WP_User object.
	 * @param array   $post_data Raw $_POST data (already nonce-verified).
	 */
	do_action( 'buddynext_admin_member_profile_saved', $user_id, $wp_user, $_POST );

	wp_safe_redirect(
		add_query_arg( 'saved', '1', $redirect_url )
	);
	exit;
}

	// ── Export ─────────────────────────────────────────────────────────────────

	/**
	 * Stream a CSV export of all members directly to output.
	 *
	 * Fetches users in batches of 500 to avoid out-of-memory errors on large
	 * sites. Suspension status is pre-fetched in a single query per batch so
	 * there are no per-row DB round-trips.
	 *
	 * Columns: ID, Login, Email, Display Name, Registered, Suspended.
	 *
	 * @return void
	 */
public function export_members_csv(): void {
	global $wpdb;

	$output = fopen( 'php://output', 'w' );
	if ( false === $output ) {
		return;
	}

	// Header row.
	fputcsv( $output, array( 'ID', 'Login', 'Email', 'Display Name', 'Registered', 'Suspended' ) );

	// Pre-fetch ALL currently suspended user IDs in one query so the per-batch
	// lookup below is an O(1) array_key_exists check with no extra queries.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$suspended_ids = array_flip(
		(array) $wpdb->get_col(
			"SELECT DISTINCT user_id FROM {$wpdb->prefix}bn_user_suspensions
				 WHERE lifted_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())"
		)
	);

	$offset     = 0;
	$batch_size = 500;

	while ( true ) {
		$users = get_users(
			array(
				'number'  => $batch_size,
				'offset'  => $offset,
				'fields'  => array( 'ID', 'user_login', 'user_email', 'display_name', 'user_registered' ),
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		if ( empty( $users ) ) {
			break;
		}

		// Prime usermeta cache for this batch — prevents extra queries if any
		// downstream hook reads meta during or after this loop.
		update_meta_cache( 'user', wp_list_pluck( $users, 'ID' ) );

		foreach ( $users as $user ) {
			$suspended = isset( $suspended_ids[ $user->ID ] ) ? 'yes' : 'no';
			fputcsv(
				$output,
				array(
					$user->ID,
					$user->user_login,
					$user->user_email,
					$user->display_name,
					$user->user_registered,
					$suspended,
				)
			);
		}

		$offset += $batch_size;
	}

	fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
}

	// ── AdminPageBase interface ────────────────────────────────────────────────

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
protected function get_title(): string {
	return __( 'Members', 'buddynext' );
}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
protected function get_subtitle(): string {
	return __( 'Manage your community members', 'buddynext' );
}

	/**
	 * Render the members page with tab routing.
	 *
	 * Routes to the member edit view when ?view=edit-member, otherwise
	 * renders the Members or Profile Fields tab.
	 *
	 * @return void
	 */
protected function render_content(): void {
	$base_url = admin_url( 'admin.php?page=buddynext-members' );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$active_tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'members' ) );
	if ( ! in_array( $active_tab, array( 'members', 'profile-fields' ), true ) ) {
		$active_tab = 'members';
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$view = sanitize_key( wp_unslash( $_GET['view'] ?? '' ) );

	if ( 'edit-member' === $view ) {
		$this->render_edit_member_view();
		return;
	}

	$this->render_tab_bar(
		array(
			'members'        => __( 'Members', 'buddynext' ),
			'profile-fields' => __( 'Profile Fields', 'buddynext' ),
		),
		$active_tab,
		$base_url
	);

	if ( 'profile-fields' === $active_tab ) {
		$this->render_profile_fields_tab();
	} else {
		$this->render_members_tab();
	}
}

	// ── Tab renderers ──────────────────────────────────────────────────────────

	/**
	 * Render the Members tab: stats, search/filter, member table, pagination.
	 *
	 * @return void
	 */
private function render_members_tab(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$page = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$status = sanitize_key( wp_unslash( $_GET['status'] ?? 'all' ) );
	if ( ! in_array( $status, array( 'all', 'active', 'suspended' ), true ) ) {
		$status = 'all';
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$role_filter = sanitize_key( wp_unslash( $_GET['role'] ?? '' ) );

	$data    = $this->list_members(
		array(
			'page'   => $page,
			'search' => $search,
			'status' => $status,
			'role'   => $role_filter,
		)
	);
	$total   = $data['total'];
	$members = $data['members'];
	$pages   = $data['pages'];

	$susp_data       = $this->list_members(
		array(
			'status'   => 'suspended',
			'per_page' => 1,
		)
	);
	$suspended_count = $susp_data['total'];
	$active_count    = max( 0, $this->get_member_count() - $suspended_count );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$action = sanitize_key( wp_unslash( $_GET['action'] ?? '' ) );
	if ( 'suspended' === $action ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Member suspended.', 'buddynext' ) . '</p></div>';
	} elseif ( 'unsuspended' === $action ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Member unsuspended.', 'buddynext' ) . '</p></div>';
	}

	// Build filter link URLs.
	$base         = admin_url( 'admin.php?page=buddynext-members' );
	$s_all        = '' !== $search ? add_query_arg( 's', rawurlencode( $search ), $base ) : $base;
	$s_active     = add_query_arg( 'status', 'active', $s_all );
	$s_susp       = add_query_arg( 'status', 'suspended', $s_all );
	$filter_links = array(
		'all'       => array(
			'url'   => $s_all,
			'label' => __( 'All', 'buddynext' ),
		),
		'active'    => array(
			'url'   => $s_active,
			'label' => __( 'Active', 'buddynext' ),
		),
		'suspended' => array(
			'url'   => $s_susp,
			'label' => __( 'Suspended', 'buddynext' ),
		),
	);
	?>
		<div class="bn-stats-row">
			<div class="bn-stat-card">
				<div class="bn-stat-label"><?php esc_html_e( 'Total Members', 'buddynext' ); ?></div>
				<div class="bn-stat-val"><?php echo esc_html( number_format_i18n( $this->get_member_count() ) ); ?></div>
			</div>
			<div class="bn-stat-card">
				<div class="bn-stat-label"><?php esc_html_e( 'Active', 'buddynext' ); ?></div>
				<div class="bn-stat-val"><?php echo esc_html( number_format_i18n( $active_count ) ); ?></div>
			</div>
			<div class="bn-stat-card">
				<div class="bn-stat-label"><?php esc_html_e( 'New This Week', 'buddynext' ); ?></div>
				<div class="bn-stat-val"><?php echo esc_html( number_format_i18n( $this->get_new_this_week_count() ) ); ?></div>
			</div>
			<div class="bn-stat-card">
				<div class="bn-stat-label"><?php esc_html_e( 'Suspended', 'buddynext' ); ?></div>
				<div class="bn-stat-val bn-stat-danger"><?php echo esc_html( number_format_i18n( $suspended_count ) ); ?></div>
			</div>
		</div>

		<div class="bn-action-row">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<input type="hidden" name="action" value="bn_export_members">
			<?php wp_nonce_field( 'bn_export_members' ); ?>
				<button type="submit" class="bn-btn-secondary"><?php esc_html_e( 'Export CSV', 'buddynext' ); ?></button>
			</form>
			<div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
			<?php foreach ( $filter_links as $key => $link ) : ?>
					<a href="<?php echo esc_url( $link['url'] ); ?>"
						class="bn-filter-tab<?php echo $status === $key ? ' active' : ''; ?>">
						<?php echo esc_html( $link['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="bn-data-table">
			<div class="bn-filter-bar">
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="bn-filter-form">
					<input type="hidden" name="page" value="buddynext-members">
				<?php if ( 'all' !== $status ) : ?>
						<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
					<?php endif; ?>
					<input type="search"
							name="s"
							class="bn-search-input"
							placeholder="<?php esc_attr_e( 'Search by name, email or username...', 'buddynext' ); ?>"
							value="<?php echo esc_attr( $search ); ?>">
					<select name="role" class="bn-filter-select">
						<option value=""><?php esc_html_e( 'All Roles', 'buddynext' ); ?></option>
					<?php foreach ( wp_roles()->get_names() as $rk => $rl ) : ?>
							<option value="<?php echo esc_attr( $rk ); ?>" <?php selected( $role_filter, $rk ); ?>>
								<?php echo esc_html( translate_user_role( $rl ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				<?php submit_button( __( 'Filter', 'buddynext' ), 'secondary', '', false, array( 'class' => 'bn-btn-secondary' ) ); ?>
				</form>
			</div>

		<?php if ( empty( $members ) ) : ?>
				<p style="padding:20px 18px;color:#6b7280;margin:0;"><?php esc_html_e( 'No members found.', 'buddynext' ); ?></p>
			<?php else : ?>
			<table class="bn-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Member', 'buddynext' ); ?></th>
						<th><?php esc_html_e( 'Email', 'buddynext' ); ?></th>
						<th><?php esc_html_e( 'Role', 'buddynext' ); ?></th>
						<th><?php esc_html_e( 'Status', 'buddynext' ); ?></th>
						<th><?php esc_html_e( 'Joined', 'buddynext' ); ?></th>
						<th><?php esc_html_e( 'Last Active', 'buddynext' ); ?></th>
						<th><?php esc_html_e( 'Last Login', 'buddynext' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'buddynext' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $members as $member ) : ?>
					<tr>
						<td>
							<div class="bn-member-cell">
								<div class="bn-avatar-initials <?php echo esc_attr( $this->get_avatar_color( $member['id'] ) ); ?>">
									<?php echo esc_html( $this->get_initials( $member['display'] ) ); ?>
								</div>
								<div class="bn-member-info">
									<div class="bn-member-name"><?php echo esc_html( $member['display'] ); ?></div>
									<div class="bn-member-username">@<?php echo esc_html( $member['login'] ); ?></div>
								</div>
							</div>
						</td>
						<td class="bn-col-email"><?php echo esc_html( $member['email'] ); ?></td>
						<td><?php echo wp_kses_post( $this->render_role_badge( $member['role'] ) ); ?></td>
						<td>
							<?php if ( $member['suspended'] ) : ?>
								<span class="bn-badge bn-badge-suspended"><?php esc_html_e( 'Suspended', 'buddynext' ); ?></span>
							<?php else : ?>
								<span class="bn-badge bn-badge-active"><?php esc_html_e( 'Active', 'buddynext' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="bn-col-muted"><?php echo esc_html( gmdate( 'M j, Y', strtotime( $member['registered'] ) ) ); ?></td>
						<td class="bn-col-muted"><?php echo esc_html( $member['last_active'] > 0 ? $this->human_time_diff_short( $member['last_active'] ) : "\xe2\x80\x94" ); ?></td>
						<td class="bn-col-muted"><?php echo esc_html( $member['last_login'] > 0 ? $this->human_time_diff_short( $member['last_login'] ) : __( 'Never', 'buddynext' ) ); ?></td>
						<td>
							<div class="bn-row-actions">
								<?php
								$edit_url = add_query_arg(
									array(
										'page'    => 'buddynext-members',
										'view'    => 'edit-member',
										'user_id' => absint( $member['id'] ),
									),
									admin_url( 'admin.php' )
								);
								?>
								<a href="<?php echo esc_url( $edit_url ); ?>" class="bn-action-link"><?php esc_html_e( 'Edit', 'buddynext' ); ?></a>
								<div class="bn-more-menu" data-uid="<?php echo absint( $member['id'] ); ?>">
									<button type="button" class="bn-more-btn" aria-label="<?php esc_attr_e( 'More actions', 'buddynext' ); ?>">&#x22EF;</button>
									<div class="bn-more-dropdown">
										<?php if ( $member['suspended'] ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="bn_unsuspend_member">
												<input type="hidden" name="user_id" value="<?php echo absint( $member['id'] ); ?>">
												<?php wp_nonce_field( 'bn_unsuspend_member' ); ?>
												<button type="submit" class="bn-dropdown-item"><?php esc_html_e( 'Unsuspend', 'buddynext' ); ?></button>
											</form>
										<?php else : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="bn_suspend_member">
												<input type="hidden" name="user_id" value="<?php echo absint( $member['id'] ); ?>">
												<?php wp_nonce_field( 'bn_suspend_member' ); ?>
												<button type="submit" class="bn-dropdown-item bn-dropdown-danger"><?php esc_html_e( 'Suspend', 'buddynext' ); ?></button>
											</form>
										<?php endif; ?>
									</div>
								</div>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<?php if ( $pages > 1 ) : ?>
			<div class="bn-pagination">
				<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
					<?php
					$paged_url = add_query_arg(
						array_filter(
							array(
								'page'   => 'buddynext-members',
								'paged'  => $i > 1 ? $i : false,
								's'      => '' !== $search ? $search : false,
								'status' => 'all' !== $status ? $status : false,
								'role'   => '' !== $role_filter ? $role_filter : false,
							)
						),
						admin_url( 'admin.php' )
					);
					?>
					<a href="<?php echo esc_url( $paged_url ); ?>"
						class="bn-page-link<?php echo $i === $page ? ' current' : ''; ?>">
						<?php echo esc_html( (string) $i ); ?>
					</a>
				<?php endfor; ?>
			</div>
			<?php endif; ?>

		</div><!-- .bn-data-table -->
		<script>
		(function() {
			document.querySelectorAll('.bn-more-btn').forEach(function(btn) {
				btn.addEventListener('click', function(e) {
					e.stopPropagation();
					var menu = btn.closest('.bn-more-menu');
					document.querySelectorAll('.bn-more-menu.open').forEach(function(m) {
						if (m !== menu) m.classList.remove('open');
					});
					menu.classList.toggle('open');
				});
			});
			document.addEventListener('click', function() {
				document.querySelectorAll('.bn-more-menu.open').forEach(function(m) { m.classList.remove('open'); });
			});
		})();
		</script>
		<?php
}

	/**
	 * Render the Profile Fields tab: group cards with nested field lists,
	 * inline add-group and add-field forms.
	 *
	 * @return void
	 */
private function render_profile_fields_tab(): void {
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
											title="<?php esc_attr_e( 'Edit field', 'buddynext' ); ?>">&#x270E;</button>
										<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="margin:0;" class="bn-del-form">
											<input type="hidden" name="action" value="bn_delete_profile_field">
											<input type="hidden" name="field_id" value="<?php echo absint( $fid ); ?>">
											<?php wp_nonce_field( 'bn_delete_profile_field_' . $fid ); ?>
											<button type="button" class="bn-pf-del-field bn-del-trigger" title="<?php esc_attr_e( 'Remove field', 'buddynext' ); ?>">&#x2715;</button>
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
												<p class="bn-pf-opts-hint"><?php esc_html_e( 'Each line becomes one selectable option. Example: United States, Canada, United Kingdom â each on its own line.', 'buddynext' ); ?></p>
											</div>
											<!-- Date display config (shown for date / daterange types) -->
											<div id="bn-ef-date-<?php echo absint( $fid ); ?>" class="bn-pf-opts-wrap" style="<?php echo $is_date_type ? '' : 'display:none;'; ?>">
												<label for="bn-ef-date-d-<?php echo absint( $fid ); ?>">
													<?php esc_html_e( 'Display as', 'buddynext' ); ?>
												</label>
												<select id="bn-ef-date-d-<?php echo absint( $fid ); ?>" name="date_display">
													<option value="date" <?php selected( $date_display_val, 'date' ); ?>><?php esc_html_e( 'Full date â Jan 15, 1990', 'buddynext' ); ?></option>
													<option value="month_year" <?php selected( $date_display_val, 'month_year' ); ?>><?php esc_html_e( 'Month â Year â Jan 1990', 'buddynext' ); ?></option>
													<option value="year" <?php selected( $date_display_val, 'year' ); ?>><?php esc_html_e( 'Year only â 1990', 'buddynext' ); ?></option>
													<option value="age" <?php selected( $date_display_val, 'age' ); ?>><?php esc_html_e( 'Calculated age â 34 years old', 'buddynext' ); ?></option>
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

	/**
	 * Render the member edit view for a single user.
	 *
	 * Called when ?view=edit-member&user_id=X is present.
	 *
	 * @return void
	 */
private function render_edit_member_view(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id = absint( wp_unslash( $_GET['user_id'] ?? 0 ) );
		$wp_user = $user_id > 0 ? get_userdata( $user_id ) : false;

if ( ! $wp_user || $user_id <= 0 ) {
	echo '<div class="notice notice-error"><p>' . esc_html__( 'User not found.', 'buddynext' ) . '</p></div>';
	return;
}

		$back_url = admin_url( 'admin.php?page=buddynext-members' );
		$profile  = buddynext_service( 'profiles' )->get_profile( $user_id, $user_id );
		$groups   = $profile['groups'] ?? array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$saved = absint( wp_unslash( $_GET['saved'] ?? 0 ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bn_error = sanitize_key( wp_unslash( $_GET['bn_error'] ?? '' ) );
if ( $saved ) {
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Profile updated successfully.', 'buddynext' ) . '</p></div>';
}
if ( 'avatar_size' === $bn_error ) {
	echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Photo not saved: file exceeds the 2MB limit.', 'buddynext' ) . '</p></div>';
} elseif ( 'avatar_type' === $bn_error ) {
	echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Photo not saved: only JPEG, PNG, GIF, or WebP files are allowed.', 'buddynext' ) . '</p></div>';
} elseif ( 'slug_taken' === $bn_error ) {
	echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Profile URL slug is already in use. Please choose a different one.', 'buddynext' ) . '</p></div>';
}
?>
		<style>
		.bn-edit-member-back { display:inline-flex; align-items:center; gap:6px; font-size:13px; color:#0073aa; text-decoration:none; margin-bottom:16px; font-weight:500; }
		.bn-edit-member-back:hover { text-decoration:underline; }
		.bn-edit-textarea { width:100%; border:1px solid #ddd; border-radius:4px; padding:8px 10px; font-size:13px; font-family:inherit; resize:vertical; min-height:80px; box-sizing:border-box; }
		.bn-edit-textarea:focus { border-color:#0073aa; box-shadow:0 0 0 1px #0073aa; outline:none; }
		.bn-repeater-entry { border:1px solid #e9ecef; border-radius:6px; padding:14px; margin-bottom:12px; background:#fafafa; }
		.bn-repeater-entry-header { display:flex; align-items:center; justify-content:space-between; font-size:12px; font-weight:700; color:#6b7280; margin-bottom:10px; }
		.bn-repeater-remove { background:none; border:none; color:#9ca3af; cursor:pointer; font-size:14px; padding:2px 6px; line-height:1; border-radius:3px; transition:color .15s; }
		.bn-repeater-remove:hover { color:#dc2626; }
		.bn-repeater-add { margin-top:4px; background:none; border:1px dashed #d1d5db; border-radius:5px; color:#0073aa; cursor:pointer; font-size:12px; font-weight:600; padding:7px 14px; width:100%; transition:background .15s, border-color .15s; }
		.bn-repeater-add:hover { background:#f0f7fc; border-color:#0073aa; }
		.bn-member-hero { background:#fff; border:1px solid #e9ecef; border-radius:10px; padding:20px 24px; display:flex; align-items:center; gap:16px; margin-bottom:20px; }
		.bn-hero-avatar-img { width:64px; height:64px; border-radius:50%; object-fit:cover; flex-shrink:0; }
		.bn-hero-avatar-initials { width:64px !important; height:64px !important; font-size:22px !important; flex-shrink:0; }
		.bn-member-hero-info { flex:1; min-width:0; }
		.bn-hero-name { font-size:18px; font-weight:700; color:#111827; margin-bottom:4px; }
		.bn-hero-meta { display:flex; align-items:center; gap:6px; font-size:13px; flex-wrap:wrap; margin-bottom:4px; }
		.bn-hero-username { color:#6b7280; }
		.bn-hero-email { color:#6b7280; }
		.bn-hero-sep { color:#d1d5db; }
		.bn-hero-stats { font-size:12px; color:#9ca3af; display:flex; gap:6px; flex-wrap:wrap; }
		.bn-member-hero-actions { display:flex; align-items:center; gap:8px; flex-shrink:0; }
		.bn-hero-danger-btn { background:#fff; border:1px solid #fca5a5; color:#dc2626; border-radius:5px; padding:7px 14px; font-size:13px; font-weight:600; cursor:pointer; font-family:inherit; }
		.bn-hero-danger-btn:hover { background:#fef2f2; border-color:#dc2626; }
		@media (max-width: 640px) {
			.bn-member-hero { flex-direction:column; align-items:flex-start; }
			.bn-member-hero-actions { width:100%; }
		}
		</style>

		<a href="<?php echo esc_url( $back_url ); ?>" class="bn-edit-member-back">
			&#8592; <?php esc_html_e( 'Back to Members', 'buddynext' ); ?>
		</a>

		<div class="bn-member-hero">
			<div class="bn-member-hero-avatar">
				<?php
				$avatar_url = (string) get_user_meta( $user_id, 'bn_avatar', true );
				if ( '' !== $avatar_url ) :
					?>
					<img src="<?php echo esc_url( $avatar_url ); ?>" alt="" class="bn-hero-avatar-img">
				<?php else : ?>
					<div class="bn-avatar-initials bn-hero-avatar-initials <?php echo esc_attr( $this->get_avatar_color( $user_id ) ); ?>">
						<?php echo esc_html( $this->get_initials( $wp_user->display_name ) ); ?>
					</div>
				<?php endif; ?>
			</div>
			<div class="bn-member-hero-info">
				<div class="bn-hero-name"><?php echo esc_html( $wp_user->display_name ); ?></div>
				<div class="bn-hero-meta">
					<span class="bn-hero-username">@<?php echo esc_html( $wp_user->user_login ); ?></span>
					<span class="bn-hero-sep">&middot;</span>
					<span class="bn-hero-email"><?php echo esc_html( $wp_user->user_email ); ?></span>
					<span class="bn-hero-sep">&middot;</span>
					<?php echo wp_kses_post( $this->render_role_badge( ( (array) $wp_user->roles )[0] ?? 'subscriber' ) ); ?>
				</div>
				<div class="bn-hero-stats">
					<?php
					$last_login = (int) get_user_meta( $user_id, 'bn_last_login', true );
					$joined     = gmdate( 'M j, Y', strtotime( $wp_user->user_registered ) );
					?>
					<span><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Joined %s', 'buddynext' ), $joined ) ); ?></span>
					<span class="bn-hero-sep">&middot;</span>
					<span><?php echo esc_html( sprintf( /* translators: %s: time string */ __( 'Last login: %s', 'buddynext' ), $last_login > 0 ? $this->human_time_diff_short( $last_login ) : __( 'Never', 'buddynext' ) ) ); ?></span>
				</div>
			</div>
			<div class="bn-member-hero-actions">
				<?php if ( buddynext_service( 'moderation' )->is_suspended( $user_id ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bn_unsuspend_member">
						<input type="hidden" name="user_id" value="<?php echo absint( $user_id ); ?>">
						<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( remove_query_arg( 'saved', sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ) ); ?>">
						<?php wp_nonce_field( 'bn_unsuspend_member' ); ?>
						<button type="submit" class="bn-btn-secondary"><?php esc_html_e( 'Unsuspend', 'buddynext' ); ?></button>
					</form>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bn_suspend_member">
						<input type="hidden" name="user_id" value="<?php echo absint( $user_id ); ?>">
						<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( remove_query_arg( 'saved', sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ) ); ?>">
						<?php wp_nonce_field( 'bn_suspend_member' ); ?>
						<button type="submit" class="bn-hero-danger-btn"><?php esc_html_e( 'Suspend Member', 'buddynext' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		</div>

		<?php
		/**
		 * Fires before the edit-member admin form.
		 *
		 * @param int     $user_id User ID being edited.
		 * @param WP_User $wp_user WP_User object.
		 */
		do_action( 'buddynext_before_edit_member_form', $user_id, $wp_user );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="bn_save_member_profile">
			<input type="hidden" name="user_id" value="<?php echo absint( $user_id ); ?>">
			<?php wp_nonce_field( 'bn_save_member_profile' ); ?>

			<?php
			// Avatar section.
			$existing_avatar = (string) get_user_meta( $user_id, 'bn_avatar', true );
			$this->open_section( __( 'Profile Photo', 'buddynext' ) );
			?>
			<div class="bn-field-row">
				<div class="bn-label"><?php esc_html_e( 'Current Photo', 'buddynext' ); ?></div>
				<div class="bn-control">
					<?php if ( '' !== $existing_avatar ) : ?>
					<img src="<?php echo esc_url( $existing_avatar ); ?>" alt="" style="width:80px;height:80px;border-radius:50%;object-fit:cover;display:block;margin-bottom:10px;">
				<?php else : ?>
					<div class="bn-avatar-initials <?php echo esc_attr( $this->get_avatar_color( $user_id ) ); ?>" style="width:80px;height:80px;font-size:28px;margin-bottom:10px;"><?php echo esc_html( $this->get_initials( $wp_user->display_name ) ); ?></div>
				<?php endif; ?>
					<?php if ( '' !== $existing_avatar ) : ?>
						<label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#dc2626;margin-bottom:8px;">
							<input type="checkbox" name="bn_remove_avatar" value="1">
							<?php esc_html_e( 'Remove current photo', 'buddynext' ); ?>
						</label>
					<?php endif; ?>
					<input type="file" name="bn_avatar" accept="image/jpeg,image/png,image/gif,image/webp" style="font-size:13px;">
					<p style="font-size:11px;color:#aeaca8;margin:6px 0 0;"><?php esc_html_e( 'Max 2MB. JPEG, PNG, GIF, or WebP.', 'buddynext' ); ?></p>
				</div>
			</div>
			<?php
			$this->close_section();

			// Account section — core WP fields.
			$slug         = (string) get_user_meta( $user_id, 'bn_profile_slug', true );
			$all_roles    = wp_roles()->get_names();
			$current_role = ( (array) $wp_user->roles )[0] ?? '';
			$this->open_section( __( 'Account', 'buddynext' ) );
			$this->render_text_row(
				'display_name',
				__( 'Display Name', 'buddynext' ),
				$wp_user->display_name,
				__( 'Shown publicly across the community.', 'buddynext' )
			);
			?>
			<div class="bn-field-row">
				<div class="bn-label"><label for="bn-user-email"><?php esc_html_e( 'Email Address', 'buddynext' ); ?></label></div>
				<div class="bn-control">
					<input type="email"
						id="bn-user-email"
						name="bn_user_email"
						value="<?php echo esc_attr( $wp_user->user_email ); ?>"
						class="bn-text-input regular-text">
				</div>
			</div>
			<div class="bn-field-row">
				<div class="bn-label"><label for="bn-user-role"><?php esc_html_e( 'Role', 'buddynext' ); ?></label></div>
				<div class="bn-control">
					<select id="bn-user-role" name="bn_user_role" class="bn-text-input">
						<?php foreach ( $all_roles as $role_key => $role_label ) : ?>
							<option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( $current_role, $role_key ); ?>>
								<?php echo esc_html( translate_user_role( $role_label ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
			<div class="bn-field-row">
				<div class="bn-label"><label for="bn-profile-slug"><?php esc_html_e( 'Profile URL Slug', 'buddynext' ); ?></label></div>
				<div class="bn-control">
					<input type="text"
						id="bn-profile-slug"
						name="bn_profile_slug"
						value="<?php echo esc_attr( $slug ); ?>"
						class="bn-text-input regular-text">
					<p style="font-size:11px;color:#aeaca8;margin:4px 0 0;"><?php esc_html_e( 'Leave blank to use the default (user-{id}). Must be unique.', 'buddynext' ); ?></p>
				</div>
			</div>
			<?php
			$this->close_section();

			// Render each profile group.
			foreach ( $groups as $group ) :
				$this->open_section( esc_html( $group['label'] ) );

				if ( 'repeater' === $group['type'] ) :
					$entries    = $group['entries'] ?? array();
					$group_key  = $group['group_key'];
					$group_id   = (int) $group['id'];
					$field_defs = $this->get_group_field_defs( $group_id );
					// Show existing entries; always show at least one blank entry row.
					if ( empty( $entries ) ) {
						$entries = array( array() );
					}
					?>
					<div class="bn-repeater-entries" id="bn-repeater-<?php echo esc_attr( $group_key ); ?>">
					<?php
					foreach ( $entries as $e_idx => $entry_fields ) :
						?>
						<div class="bn-repeater-entry">
							<div class="bn-repeater-entry-header">
								<span class="bn-repeater-entry-label">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: entry number */
											__( 'Entry %d', 'buddynext' ),
											(int) $e_idx + 1
										)
									);
									?>
								</span>
								<?php if ( $e_idx > 0 ) : ?>
									<button type="button" class="bn-repeater-remove" aria-label="<?php esc_attr_e( 'Remove entry', 'buddynext' ); ?>">&#x2715;</button>
								<?php endif; ?>
							</div>
							<?php
							foreach ( $entry_fields as $entry_field ) :
								$this->render_repeater_field_input(
									$group_key,
									$e_idx,
									$entry_field
								);
							endforeach;
							// If no entry fields returned (blank entry), show all fields for the group.
							if ( empty( $entry_fields ) ) :
								foreach ( $field_defs as $field_def ) :
									$this->render_repeater_field_input(
										$group_key,
										$e_idx,
										array(
											'field_key' => $field_def['field_key'],
											'label'     => $field_def['label'],
											'type'      => $field_def['type'],
											'value'     => null,
										)
									);
								endforeach;
							endif;
							?>
						</div>
						<?php
					endforeach;
					?>
					</div>
					<template id="bn-repeater-tpl-<?php echo esc_attr( $group_key ); ?>">
						<div class="bn-repeater-entry">
							<div class="bn-repeater-entry-header">
								<span class="bn-repeater-entry-label"></span>
								<button type="button" class="bn-repeater-remove" aria-label="<?php esc_attr_e( 'Remove entry', 'buddynext' ); ?>">&#x2715;</button>
							</div>
							<?php foreach ( $field_defs as $field_def ) : ?>
								<?php $this->render_repeater_field_template( $group_key, $field_def ); ?>
							<?php endforeach; ?>
						</div>
					</template>
					<button type="button" class="bn-repeater-add" data-group="<?php echo esc_attr( $group_key ); ?>">+ <?php esc_html_e( 'Add Entry', 'buddynext' ); ?></button>
					<?php $this->output_repeater_js( $group_key ); ?>
				<?php else :
					// Flat group.
					$flat_fields = $group['fields'] ?? array();
					foreach ( $flat_fields as $field ) :
						$this->render_flat_field_input( $field );
					endforeach;
					if ( empty( $flat_fields ) ) :
						echo '<p style="color:#9ca3af;font-size:12px;margin:0;">' . esc_html__( 'No fields in this group.', 'buddynext' ) . '</p>';
					endif;
				endif;

				$this->close_section();
			endforeach;
			?>

			<?php
			/**
			 * Fires after all profile group sections inside the edit-member form.
			 * Use this to append custom card sections before the Save button.
			 *
			 * @param int     $user_id User ID being edited.
			 * @param WP_User $wp_user WP_User object.
			 */
			do_action( 'buddynext_edit_member_sections', $user_id, $wp_user );
			?>
			<div class="bn-save-bar">
					<?php submit_button( __( 'Save Profile', 'buddynext' ), 'primary bn-btn-save', 'submit', false ); ?>
			</div>
		</form>
		<?php
		/**
		 * Fires after the edit-member admin form.
		 *
		 * @param int     $user_id User ID being edited.
		 * @param WP_User $wp_user WP_User object.
		 */
		do_action( 'buddynext_after_edit_member_form', $user_id, $wp_user );
		?>
					<?php
				}

				// ── Private rendering helpers ──────────────────────────────────────────────

				/**
				 * Get initials from a display name (up to 2 characters).
				 *
				 * @param string $display_name Display name.
				 * @return string
				 */
				private function get_initials( string $display_name ): string {
					$parts = array_filter( explode( ' ', trim( $display_name ) ) );
					if ( empty( $parts ) ) {
						return '?';
					}
					$first = mb_substr( reset( $parts ), 0, 1 );
					$last  = count( $parts ) > 1 ? mb_substr( end( $parts ), 0, 1 ) : '';
					return mb_strtoupper( $first . $last );
				}

				/**
				 * Return a deterministic CSS class for avatar background based on user ID.
				 *
				 * @param int $user_id User ID.
				 * @return string CSS class name.
				 */
				private function get_avatar_color( int $user_id ): string {
					$colors = array( 'av-brand', 'av-green', 'av-purple', 'av-orange', 'av-pink', 'av-teal', 'av-rose', 'av-indigo' );
					return $colors[ $user_id % count( $colors ) ];
				}

				/**
				 * Render a role badge with appropriate color.
				 *
				 * @param string $role WP role slug.
				 * @return string HTML badge.
				 */
				private function render_role_badge( string $role ): string {
					$labels = array(
						'administrator' => array(
							'label' => __( 'Admin', 'buddynext' ),
							'class' => 'bn-badge-role-admin',
						),
						'editor'        => array(
							'label' => __( 'Editor', 'buddynext' ),
							'class' => 'bn-badge-role-editor',
						),
						'author'        => array(
							'label' => __( 'Author', 'buddynext' ),
							'class' => 'bn-badge-role-author',
						),
						'contributor'   => array(
							'label' => __( 'Contributor', 'buddynext' ),
							'class' => 'bn-badge-role-contrib',
						),
						'subscriber'    => array(
							'label' => __( 'Member', 'buddynext' ),
							'class' => 'bn-badge-role-member',
						),
					);
					$map    = $labels[ $role ] ?? array(
						'label' => ucfirst( $role ),
						'class' => 'bn-badge-role-member',
					);
					return '<span class="bn-badge ' . esc_attr( $map['class'] ) . '">' . esc_html( $map['label'] ) . '</span>';
				}

				/**
				 * Return a short human-readable time difference string.
				 *
				 * @param int $timestamp Unix timestamp.
				 * @return string e.g. "2h ago", "3d ago", "1w ago".
				 */
				private function human_time_diff_short( int $timestamp ): string {
					if ( $timestamp <= 0 ) {
						return "\xe2\x80\x94";
					}
					$diff = max( 0, time() - $timestamp );
					if ( $diff < 60 ) {
						return __( 'Just now', 'buddynext' );
					}
					if ( $diff < 3600 ) {
						return (string) round( $diff / 60 ) . 'm ago';
					}
					if ( $diff < 86400 ) {
						return (string) round( $diff / 3600 ) . 'h ago';
					}
					if ( $diff < 604800 ) {
						return (string) round( $diff / 86400 ) . 'd ago';
					}
					if ( $diff < 2592000 ) {
						return (string) round( $diff / 604800 ) . 'w ago';
					}
					return gmdate( 'M j, Y', $timestamp );
				}

				/**
				 * Render an editable input for a flat profile field.
				 *
				 * @param array<string, mixed> $field Field data including field_key, label, type, value.
				 * @return void
				 */
				private function render_flat_field_input( array $field ): void {
					$key     = sanitize_key( (string) ( $field['field_key'] ?? '' ) );
					$label   = (string) ( $field['label'] ?? $key );
					$type    = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
					$raw_val = $field['value'] ?? '';
					$value   = is_array( $raw_val ) ? $raw_val : (string) $raw_val;
					$options = array();
					if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
						$options = $field['options'];
					}

					$input_id = 'bn-pf-' . $key;
					?>
		<div class="bn-field-row">
			<div class="bn-label"><label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label></div>
			<div class="bn-control">
					<?php if ( 'textarea' === $type ) : ?>
				<textarea id="<?php echo esc_attr( $input_id ); ?>"
							name="<?php echo esc_attr( $key ); ?>"
							class="bn-edit-textarea"><?php echo esc_textarea( is_array( $value ) ? wp_json_encode( $value ) : $value ); ?></textarea>
			<?php elseif ( 'select' === $type ) : ?>
				<select id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $key ); ?>" class="bn-text-input">
					<option value=""><?php esc_html_e( '-- Select --', 'buddynext' ); ?></option>
					<?php foreach ( $options as $opt ) : ?>
						<option value="<?php echo esc_attr( (string) $opt ); ?>" <?php selected( is_array( $value ) ? '' : $value, (string) $opt ); ?>><?php echo esc_html( (string) $opt ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php elseif ( 'multiselect' === $type ) : ?>
				<select id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $key ); ?>[]" multiple class="bn-text-input">
					<?php
					$selected_vals = is_array( $value ) ? $value : (array) json_decode( $value, true );
					foreach ( $options as $opt ) :
						$is_sel = in_array( (string) $opt, array_map( 'strval', (array) $selected_vals ), true );
						?>
						<option value="<?php echo esc_attr( (string) $opt ); ?>"<?php echo $is_sel ? ' selected' : ''; ?>><?php echo esc_html( (string) $opt ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php elseif ( 'radio' === $type ) : ?>
				<div class="bn-radio-group">
				<?php foreach ( $options as $opt ) : ?>
					<label style="display:inline-flex;align-items:center;gap:4px;margin-right:12px;font-size:13px;">
						<input type="radio"
							name="<?php echo esc_attr( $key ); ?>"
							value="<?php echo esc_attr( (string) $opt ); ?>"
							<?php checked( is_array( $value ) ? '' : $value, (string) $opt ); ?>>
						<?php echo esc_html( (string) $opt ); ?>
					</label>
				<?php endforeach; ?>
				</div>
			<?php elseif ( 'checkbox' === $type ) : ?>
				<div class="bn-checkbox-group">
				<?php
				$checked_vals = is_array( $value ) ? $value : (array) json_decode( $value, true );
				foreach ( $options as $opt ) :
					$is_chk = in_array( (string) $opt, array_map( 'strval', (array) $checked_vals ), true );
					?>
					<label style="display:inline-flex;align-items:center;gap:4px;margin-right:12px;font-size:13px;">
						<input type="checkbox"
							name="<?php echo esc_attr( $key ); ?>[]"
							value="<?php echo esc_attr( (string) $opt ); ?>"
							<?php echo $is_chk ? 'checked' : ''; ?>>
						<?php echo esc_html( (string) $opt ); ?>
					</label>
				<?php endforeach; ?>
				</div>
			<?php elseif ( 'toggle' === $type ) : ?>
				<label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;">
					<input type="checkbox"
						id="<?php echo esc_attr( $input_id ); ?>"
						name="<?php echo esc_attr( $key ); ?>"
						value="1"
						<?php checked( is_array( $value ) ? '' : $value, '1' ); ?>>
					<?php esc_html_e( 'Yes', 'buddynext' ); ?>
				</label>
			<?php elseif ( 'rating' === $type ) : ?>
				<input type="number"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $key ); ?>"
					value="<?php echo esc_attr( is_array( $value ) ? '' : $value ); ?>"
					min="1" max="5" step="1"
					class="bn-text-input">
			<?php elseif ( 'date' === $type ) : ?>
				<input type="date"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $key ); ?>"
					value="<?php echo esc_attr( is_array( $value ) ? '' : $value ); ?>"
					class="bn-text-input">
			<?php elseif ( 'email' === $type ) : ?>
				<input type="email"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $key ); ?>"
					value="<?php echo esc_attr( is_array( $value ) ? '' : $value ); ?>"
					class="bn-text-input regular-text">
			<?php elseif ( 'url' === $type || 'social' === $type ) : ?>
				<input type="url"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $key ); ?>"
					value="<?php echo esc_attr( is_array( $value ) ? '' : $value ); ?>"
					class="bn-text-input regular-text">
			<?php elseif ( 'number' === $type ) : ?>
				<input type="number"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $key ); ?>"
					value="<?php echo esc_attr( is_array( $value ) ? '' : $value ); ?>"
					class="bn-text-input">
			<?php elseif ( 'phone' === $type ) : ?>
				<input type="tel"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $key ); ?>"
					value="<?php echo esc_attr( is_array( $value ) ? '' : $value ); ?>"
					class="bn-text-input regular-text">
			<?php else : ?>
				<input type="text"
						id="<?php echo esc_attr( $input_id ); ?>"
						name="<?php echo esc_attr( $key ); ?>"
						value="<?php echo esc_attr( is_array( $value ) ? '' : $value ); ?>"
						class="bn-text-input regular-text">
			<?php endif; ?>
			</div>
		</div>
					<?php
				}

				/**
				 * Render an editable input for a repeater entry field.
				 *
				 * Input name follows the shape: group_key[entry_index][field_key].
				 *
				 * @param string               $group_key  The parent group's group_key.
				 * @param int                  $entry_idx  Zero-based entry index.
				 * @param array<string, mixed> $field      Field data: field_key, label, type, value.
				 * @return void
				 */
				private function render_repeater_field_input( string $group_key, int $entry_idx, array $field ): void {
					$key      = sanitize_key( (string) ( $field['field_key'] ?? '' ) );
					$label    = (string) ( $field['label'] ?? $key );
					$type     = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
					$value    = (string) ( $field['value'] ?? '' );
					$name     = esc_attr( $group_key ) . '[' . absint( $entry_idx ) . '][' . esc_attr( $key ) . ']';
					$input_id = 'bn-pf-' . esc_attr( $group_key ) . '-' . absint( $entry_idx ) . '-' . esc_attr( $key );
					?>
		<div class="bn-field">
			<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label>
					<?php if ( 'textarea' === $type ) : ?>
				<textarea id="<?php echo esc_attr( $input_id ); ?>"
							name="<?php echo esc_attr( $name ); ?>"
							class="bn-edit-textarea"><?php echo esc_textarea( $value ); ?></textarea>
			<?php elseif ( 'url' === $type ) : ?>
				<input type="url"
						id="<?php echo esc_attr( $input_id ); ?>"
						name="<?php echo esc_attr( $name ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						class="bn-text-input regular-text">
			<?php else : ?>
				<input type="text"
						id="<?php echo esc_attr( $input_id ); ?>"
						name="<?php echo esc_attr( $name ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						class="bn-text-input regular-text">
			<?php endif; ?>
		</div>
					<?php
				}

				/**
				 * Return field definitions for a given group ID (used for blank repeater rows).
				 *
				 * @param int $group_id Group ID.
				 * @return array<int, array<string, mixed>>
				 */
				private function get_group_field_defs( int $group_id ): array {
					$all_groups = buddynext_service( 'profiles' )->get_fields();

					foreach ( $all_groups as $group ) {
						if ( (int) $group['id'] === $group_id ) {
							return $group['fields'] ?? array();
						}
					}

					return array();
				}

				/**
				 * Render a blank repeater field input for use inside a <template> element.
				 * Uses the literal string __idx__ as the entry-index placeholder so that
				 * JavaScript can replace it with the real index when cloning the template.
				 *
				 * @param string               $group_key Group key.
				 * @param array<string, mixed> $field     Field definition: field_key, label, type.
				 * @return void
				 */
				private function render_repeater_field_template( string $group_key, array $field ): void {
					$key      = sanitize_key( (string) ( $field['field_key'] ?? '' ) );
					$label    = (string) ( $field['label'] ?? $key );
					$type     = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
					$name     = esc_attr( $group_key ) . '[__idx__][' . esc_attr( $key ) . ']';
					$input_id = 'bn-pf-' . esc_attr( $group_key ) . '-__idx__-' . esc_attr( $key );
					?>
		<div class="bn-field">
			<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label>
					<?php if ( 'textarea' === $type ) : ?>
				<textarea id="<?php echo esc_attr( $input_id ); ?>"
							name="<?php echo esc_attr( $name ); ?>"
							class="bn-edit-textarea"></textarea>
			<?php elseif ( 'url' === $type ) : ?>
				<input type="url"
						id="<?php echo esc_attr( $input_id ); ?>"
						name="<?php echo esc_attr( $name ); ?>"
						value=""
						class="bn-text-input regular-text">
			<?php else : ?>
				<input type="text"
						id="<?php echo esc_attr( $input_id ); ?>"
						name="<?php echo esc_attr( $name ); ?>"
						value=""
						class="bn-text-input regular-text">
			<?php endif; ?>
		</div>
					<?php
				}

				/**
				 * Output inline JavaScript for a single repeater group's Add / Remove interactions.
				 *
				 * @param string $group_key Repeater group key.
				 * @return void
				 */
				private function output_repeater_js( string $group_key ): void {
					?>
		<script>
		( function () {
			var container = document.getElementById( 'bn-repeater-<?php echo esc_js( $group_key ); ?>' );
			var tpl       = document.getElementById( 'bn-repeater-tpl-<?php echo esc_js( $group_key ); ?>' );
			var addBtn    = document.querySelector( '[data-group="<?php echo esc_js( $group_key ); ?>"]' );
			if ( ! container || ! tpl || ! addBtn ) { return; }

			function applyIdx( node, idx ) {
				if ( node.nodeType !== 1 ) { return; }
				var attrs = [ 'id', 'name', 'for' ];
				attrs.forEach( function ( attr ) {
					var val = node.getAttribute( attr );
					if ( val && val.indexOf( '__idx__' ) !== -1 ) {
						node.setAttribute( attr, val.replace( /__idx__/g, String( idx ) ) );
					}
				} );
				node.childNodes.forEach( function ( child ) { applyIdx( child, idx ); } );
			}

			function renumber() {
				container.querySelectorAll( '.bn-repeater-entry' ).forEach( function ( entry, i ) {
					var lbl = entry.querySelector( '.bn-repeater-entry-label' );
					if ( lbl ) { lbl.textContent = '<?php echo esc_js( __( 'Entry', 'buddynext' ) ); ?> ' + ( i + 1 ); }
				} );
			}

			function bindRemove( btn ) {
				btn.addEventListener( 'click', function () {
					if ( container.querySelectorAll( '.bn-repeater-entry' ).length > 1 ) {
						btn.closest( '.bn-repeater-entry' ).remove();
						renumber();
					}
				} );
			}

			container.querySelectorAll( '.bn-repeater-remove' ).forEach( bindRemove );

			addBtn.addEventListener( 'click', function () {
				var idx      = container.querySelectorAll( '.bn-repeater-entry' ).length;
				var newEntry = document.importNode( tpl.content, true ).firstElementChild;
				applyIdx( newEntry, idx );
				var lbl = newEntry.querySelector( '.bn-repeater-entry-label' );
				if ( lbl ) { lbl.textContent = '<?php echo esc_js( __( 'Entry', 'buddynext' ) ); ?> ' + ( idx + 1 ); }
				bindRemove( newEntry.querySelector( '.bn-repeater-remove' ) );
				container.appendChild( newEntry );
			} );
		}() );
		</script>
					<?php
				}
			}

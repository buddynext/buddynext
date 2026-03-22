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

use BuddyNext\Admin\Helpers\MemberDisplay;

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
		add_action( 'admin_post_bn_save_member_profile', array( $this, 'handle_save_member_profile' ) );
		add_action( 'wp_login', array( $this, 'handle_last_login' ), 10, 2 );

		( new \BuddyNext\Admin\Members\ProfileFieldsManager() )->register();
		( new \BuddyNext\Admin\Members\MemberExport() )->register();
		( new \BuddyNext\Admin\Members\AvatarSettings() )->register();
		( new \BuddyNext\Admin\Members\MemberTypesManager() )->register();
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
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$suspended_set = array_flip(
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT user_id FROM {$wpdb->prefix}bn_user_suspensions WHERE user_id IN ({$placeholders}) AND lifted_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())",
						...$int_ids
					)
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

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
		if ( ! in_array( $active_tab, array( 'members', 'profile-fields', 'avatar-settings', 'member-types' ), true ) ) {
			$active_tab = 'members';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = sanitize_key( wp_unslash( $_GET['view'] ?? '' ) );

		if ( 'edit-member' === $view ) {
			( new \BuddyNext\Admin\Members\MemberEditForm() )->render_edit_member_view();
			return;
		}

		$this->render_tab_bar(
			array(
				'members'         => __( 'Members', 'buddynext' ),
				'profile-fields'  => __( 'Profile Fields', 'buddynext' ),
				'avatar-settings' => __( 'Avatar & Cover', 'buddynext' ),
				'member-types'    => __( 'Member Types', 'buddynext' ),
			),
			$active_tab,
			$base_url
		);

		if ( 'profile-fields' === $active_tab ) {
			( new \BuddyNext\Admin\Members\ProfileFieldsManager() )->render_profile_fields_tab();
		} elseif ( 'avatar-settings' === $active_tab ) {
			( new \BuddyNext\Admin\Members\AvatarSettings() )->render_avatar_settings_tab();
		} elseif ( 'member-types' === $active_tab ) {
			( new \BuddyNext\Admin\Members\MemberTypesManager() )->render_member_types_tab();
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
								<div class="bn-avatar-initials <?php echo esc_attr( MemberDisplay::get_avatar_color( $member['id'] ) ); ?>">
									<?php echo esc_html( MemberDisplay::get_initials( $member['display'] ) ); ?>
								</div>
								<div class="bn-member-info">
									<div class="bn-member-name"><?php echo esc_html( $member['display'] ); ?></div>
									<div class="bn-member-username">@<?php echo esc_html( $member['login'] ); ?></div>
								</div>
							</div>
						</td>
						<td class="bn-col-email"><?php echo esc_html( $member['email'] ); ?></td>
						<td><?php MemberDisplay::render_role_badge( $member['role'] ); ?></td>
						<td>
							<?php if ( $member['suspended'] ) : ?>
								<span class="bn-badge bn-badge-suspended"><?php esc_html_e( 'Suspended', 'buddynext' ); ?></span>
							<?php else : ?>
								<span class="bn-badge bn-badge-active"><?php esc_html_e( 'Active', 'buddynext' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="bn-col-muted"><?php echo esc_html( gmdate( 'M j, Y', strtotime( $member['registered'] ) ) ); ?></td>
						<td class="bn-col-muted"><?php echo esc_html( $member['last_active'] > 0 ? MemberDisplay::human_time_diff_short( $member['last_active'] ) : "\xe2\x80\x94" ); ?></td>
						<td class="bn-col-muted"><?php echo esc_html( $member['last_login'] > 0 ? MemberDisplay::human_time_diff_short( $member['last_login'] ) : __( 'Never', 'buddynext' ) ); ?></td>
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
}

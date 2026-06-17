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

use BuddyNext\Admin\Members\MemberDisplay;

/**
 * Admin panel for managing BuddyNext community members.
 */
class Members extends AdminPageBase {

	/**
	 * Default items per page for member listing.
	 */
	private const DEFAULT_PER_PAGE = 20;

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bn_suspend_member', array( $this, 'handle_suspend' ) );
		add_action( 'admin_post_bn_unsuspend_member', array( $this, 'handle_unsuspend' ) );
		add_action( 'admin_post_bn_save_member_profile', array( $this, 'handle_save_member_profile' ) );
		// NB: the wp_login -> handle_last_login listener is wired unconditionally
		// in Plugin::boot(), not here — register() only runs in admin, but logins
		// happen in non-admin contexts (REST, wp-login.php, social login).
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		AdminHub::register_tab(
			'members',
			'directory',
			__( 'Directory', 'buddynext' ),
			array( $this, 'render_page' ),
			array(
				'subtitle' => __( 'Manage your community members', 'buddynext' ),
				'action'   => $this->build_export_action(),
			)
		);

		( new \BuddyNext\Admin\Members\ProfileFieldsManager() )->register();
		( new \BuddyNext\Admin\Members\MemberExport() )->register();
		( new \BuddyNext\Admin\Members\AvatarSettings() )->register();
		( new \BuddyNext\Admin\Members\MemberTypesManager() )->register();
		( new \BuddyNext\Admin\Members\InviteManager() )->register();
		( new \BuddyNext\Admin\Members\ApprovalManager() )->register();
	}

	/**
	 * Enqueue the Members admin JS bundle on the Members page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'buddynext-members' ) ) {
			return;
		}

		$plugin_url = defined( 'BUDDYNEXT_URL' ) ? BUDDYNEXT_URL : plugin_dir_url( dirname( __DIR__, 2 ) . '/buddynext.php' );
		$version    = defined( 'BUDDYNEXT_VERSION' ) ? BUDDYNEXT_VERSION : '1.0.0';

		wp_enqueue_script(
			'bn-admin-members',
			$plugin_url . 'assets/js/admin/members.js',
			array(),
			$version,
			true
		);

		wp_localize_script(
			'bn-admin-members',
			'bnMembersI18n',
			array(
				'entry' => __( 'Entry', 'buddynext' ),
			)
		);
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
				'id'               => $user->ID,
				'login'            => $user->user_login,
				'email'            => $user->user_email,
				'display'          => $user->display_name,
				'registered'       => $user->user_registered,
				'suspended'        => isset( $suspended_set[ $user->ID ] ),
				'pending_approval' => (bool) get_user_meta( $user->ID, 'bn_pending_approval', true ),
				'role'             => ( (array) $user->roles )[0] ?? 'subscriber',
				'last_active'      => (int) get_user_meta( $user->ID, 'bn_last_active', true ),
				'last_login'       => (int) get_user_meta( $user->ID, 'bn_last_login', true ),
				'post_count'       => 0,
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
	 * Sets the bn_suspended usermeta flag, writes a row to bn_user_suspensions,
	 * and fires both buddynext_user_suspended (canonical — EventListener listens
	 * here for email/notification dispatch) and the legacy buddynext_member_suspended
	 * hook for any third-party listeners.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public function suspend_member( int $user_id, string $reason = '' ): void {
		update_user_meta( $user_id, 'bn_suspended', '1' );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_user_suspensions',
			array(
				'user_id'      => $user_id,
				'suspended_by' => get_current_user_id(),
				'reason'       => $reason,
				'hide_posts'   => 0,
			),
			array( '%d', '%d', '%s', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$actor_id = get_current_user_id();

		/**
		 * Fires after an admin suspends a member via the Members panel.
		 * Signature matches ModerationService::suspend() so EventListener picks it up.
		 *
		 * @param int    $user_id     Suspended user ID.
		 * @param int    $actor_id    Admin who performed the suspension.
		 * @param string $reason      Reason string (empty for panel suspensions).
		 * @param null   $expires_at  NULL = indefinite suspension.
		 */
		do_action( 'buddynext_user_suspended', $user_id, $actor_id, $reason, null );

		/**
		 * Legacy hook — kept for backwards compatibility with third-party listeners.
		 *
		 * @param int $user_id   Suspended user ID.
		 * @param int $actor_id  Admin user who performed the suspension.
		 */
		do_action( 'buddynext_member_suspended', $user_id, $actor_id );
	}

	/**
	 * Lift the suspension for a community member.
	 *
	 * Removes the bn_suspended usermeta flag, marks the most-recent
	 * bn_user_suspensions row as lifted, and fires both buddynext_user_unsuspended
	 * (canonical) and the legacy buddynext_member_unsuspended hook.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public function unsuspend_member( int $user_id ): void {
		delete_user_meta( $user_id, 'bn_suspended' );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_user_suspensions
				 SET lifted_at = %s, lifted_by = %d
				 WHERE user_id = %d AND lifted_at IS NULL
				 ORDER BY id DESC
				 LIMIT 1",
				current_time( 'mysql' ),
				get_current_user_id(),
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		/**
		 * Fires after an admin lifts a suspension.
		 * Signature matches ModerationService::unsuspend_user() so EventListener
		 * sends the confirmation email/notification.
		 *
		 * @param int $user_id Unsuspended user ID.
		 */
		do_action( 'buddynext_user_unsuspended', $user_id );

		/**
		 * Legacy hook — kept for backwards compatibility with third-party listeners.
		 *
		 * @param int $user_id Unsuspended user ID.
		 */
		do_action( 'buddynext_member_unsuspended', $user_id );
	}

	/**
	 * Export all community members as a CSV string.
	 *
	 * Returns a UTF-8 CSV with a header row and one data row per member,
	 * ordered by registration date ascending.
	 *
	 * @return string CSV content ready to stream or save.
	 */
	public function export_members_csv(): string {
		$users = get_users(
			array(
				'orderby' => 'registered',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'user_login', 'user_email', 'user_registered' ),
			)
		);

		$rows   = array();
		$rows[] = 'ID,Login,Email,Registered';

		foreach ( $users as $user ) {
			$rows[] = implode(
				',',
				array(
					(int) $user->ID,
					'"' . str_replace( '"', '""', $user->user_login ) . '"',
					'"' . str_replace( '"', '""', $user->user_email ) . '"',
					'"' . str_replace( '"', '""', $user->user_registered ) . '"',
				)
			);
		}

		return implode( "\n", $rows );
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
		$reason  = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		if ( $user_id > 0 ) {
			$this->suspend_member( $user_id, $reason );
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
			// Drop the stored image variations too — usermeta alone orphans the
			// uploads/bn-avatars/{user_id}/ files on disk.
			( new \BuddyNext\Media\ImageStorageService() )->delete( 'avatar', 'user', (int) $user_id );
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

			// Organized, per-owner WebP storage (uploads/bn-avatars/{id}/) — same
			// path the front-end avatar upload uses; no attachments, no orphans.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$avatar_tmp = isset( $_FILES['bn_avatar']['tmp_name'] ) ? (string) $_FILES['bn_avatar']['tmp_name'] : '';
			$uploaded   = ( new \BuddyNext\Media\ImageStorageService() )->store( $avatar_tmp, 'avatar', 'user', (int) $user_id );

			if ( ! is_wp_error( $uploaded ) ) {
				update_user_meta( $user_id, 'bn_avatar', esc_url_raw( $uploaded ) );
				wp_cache_delete( "profile_{$user_id}_viewer_owner", 'buddynext_profiles' );
				wp_cache_delete( "profile_{$user_id}_viewer_follower", 'buddynext_profiles' );
				wp_cache_delete( "profile_{$user_id}_viewer_public", 'buddynext_profiles' );
			}
		}

		// Handle cover photo removal.
		if ( ! empty( $_POST['bn_remove_cover'] ) ) {
			delete_user_meta( $user_id, 'buddynext_cover_url' );
			delete_user_meta( $user_id, 'buddynext_cover_focal' );
			( new \BuddyNext\Media\ImageStorageService() )->delete( 'cover', 'user', (int) $user_id );
		}

		// Handle cover photo upload.
		if ( ! empty( $_FILES['bn_cover']['name'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- size validated numerically
			if ( isset( $_FILES['bn_cover']['size'] ) && (int) $_FILES['bn_cover']['size'] > 5 * 1024 * 1024 ) {
				wp_safe_redirect( add_query_arg( 'bn_error', 'cover_size', $redirect_url ) );
				exit;
			}

			$cover_allowed = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- type validated against allowlist
			if ( isset( $_FILES['bn_cover']['type'] ) && ! in_array( $_FILES['bn_cover']['type'], $cover_allowed, true ) ) {
				wp_safe_redirect( add_query_arg( 'bn_error', 'cover_type', $redirect_url ) );
				exit;
			}

			// Organized, per-owner WebP storage (uploads/bn-covers/{id}/).
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$cover_tmp      = isset( $_FILES['bn_cover']['tmp_name'] ) ? (string) $_FILES['bn_cover']['tmp_name'] : '';
			$cover_uploaded = ( new \BuddyNext\Media\ImageStorageService() )->store( $cover_tmp, 'cover', 'user', (int) $user_id );

			if ( ! is_wp_error( $cover_uploaded ) ) {
				update_user_meta( $user_id, 'buddynext_cover_url', esc_url_raw( $cover_uploaded ) );
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
		 * Receives the sanitized BuddyNext profile fields (the same map persisted
		 * via ProfileService::save_profile), NOT the raw $_POST — listeners get the
		 * data they need without unrelated/core/third-party POST fields leaking to
		 * every hooked callback (least privilege).
		 *
		 * @param int      $user_id      User ID that was saved.
		 * @param \WP_User $wp_user      WP_User object.
		 * @param array    $profile_data Sanitized BuddyNext profile field map.
		 */
		do_action( 'buddynext_admin_member_profile_saved', $user_id, $wp_user, $profile_data );

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
	 * Suppress the base subtitle paragraph.
	 *
	 * AdminHub now renders the subtitle and the Export CSV action in its
	 * standardized sub-header bar (declared via register_tab). Printing the
	 * base subtitle here too would duplicate it, so this is intentionally empty.
	 *
	 * @return void
	 */
	protected function render_page_header(): void {
		// Subtitle is owned by AdminHub's sub-header bar — see register().
	}

	/**
	 * Build the Export CSV form for AdminHub's sub-header action slot.
	 *
	 * Returns trusted, fully-escaped HTML printed verbatim by AdminHub per the
	 * Header API contract — every dynamic value is escaped here.
	 *
	 * @return string
	 */
	private function build_export_action(): string {
		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bn_export_members">
			<?php wp_nonce_field( 'bn_export_members' ); ?>
			<button type="submit" class="bn-btn" data-variant="secondary" data-size="sm">
				<?php esc_html_e( 'Export CSV', 'buddynext' ); ?>
			</button>
		</form>
		<?php
		return (string) ob_get_clean();
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
		if ( ! in_array( $active_tab, array( 'members', 'profile-fields', 'avatar-settings', 'member-types', 'invites', 'pending' ), true ) ) {
			$active_tab = 'members';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = sanitize_key( wp_unslash( $_GET['view'] ?? '' ) );

		if ( 'edit-member' === $view ) {
			( new \BuddyNext\Admin\Members\MemberEditForm() )->render_edit_member_view();
			return;
		}

		$bn_tabs = array(
			'members'         => __( 'Members', 'buddynext' ),
			'profile-fields'  => __( 'Profile Fields', 'buddynext' ),
			'avatar-settings' => __( 'Avatar & Cover', 'buddynext' ),
			'member-types'    => __( 'Member Types', 'buddynext' ),
			'invites'         => __( 'Invites', 'buddynext' ),
		);
		// Pending-approval queue is only relevant while registration is gated by approval.
		if ( 'approval' === get_option( 'buddynext_reg_mode', buddynext_default_reg_mode() ) ) {
			$bn_tabs['pending'] = __( 'Pending', 'buddynext' );
		}

		$this->render_tab_bar( $bn_tabs, $active_tab, $base_url );
		$this->open_tab_panel( $active_tab );

		if ( 'profile-fields' === $active_tab ) {
			( new \BuddyNext\Admin\Members\ProfileFieldsManager() )->render_profile_fields_tab();
		} elseif ( 'avatar-settings' === $active_tab ) {
			( new \BuddyNext\Admin\Members\AvatarSettings() )->render_avatar_settings_tab();
		} elseif ( 'member-types' === $active_tab ) {
			( new \BuddyNext\Admin\Members\MemberTypesManager() )->render_member_types_tab();
		} elseif ( 'invites' === $active_tab ) {
			( new \BuddyNext\Admin\Members\InviteManager() )->render_invites_tab();
		} elseif ( 'pending' === $active_tab ) {
			( new \BuddyNext\Admin\Members\ApprovalManager() )->render_pending_tab();
		} else {
			$this->render_members_tab();
		}

		$this->close_tab_panel();
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

		// Build status-filter chip URLs. Counts live in the KPI cards above, so
		// the chips carry labels only — no duplicated numbers.
		$base      = admin_url( 'admin.php?page=buddynext-members' );
		$s_all     = '' !== $search ? add_query_arg( 's', rawurlencode( $search ), $base ) : $base;
		$s_role    = '' !== $role_filter ? add_query_arg( 'role', $role_filter, $s_all ) : $s_all;
		$tab_links = array(
			'all'       => array(
				'url'   => $s_role,
				'label' => __( 'All', 'buddynext' ),
			),
			'active'    => array(
				'url'   => add_query_arg( 'status', 'active', $s_role ),
				'label' => __( 'Active', 'buddynext' ),
			),
			'suspended' => array(
				'url'   => add_query_arg( 'status', 'suspended', $s_role ),
				'label' => __( 'Suspended', 'buddynext' ),
			),
		);
		?>
		<div class="bn-stat-grid">
			<div class="bn-stat">
				<div class="bn-stat__label"><?php esc_html_e( 'Total Members', 'buddynext' ); ?></div>
				<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $this->get_member_count() ) ); ?></div>
			</div>
			<div class="bn-stat">
				<div class="bn-stat__label"><?php esc_html_e( 'Active', 'buddynext' ); ?></div>
				<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $active_count ) ); ?></div>
			</div>
			<div class="bn-stat">
				<div class="bn-stat__label"><?php esc_html_e( 'New This Week', 'buddynext' ); ?></div>
				<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $this->get_new_this_week_count() ) ); ?></div>
			</div>
			<div class="bn-stat">
				<div class="bn-stat__label"><?php esc_html_e( 'Suspended', 'buddynext' ); ?></div>
				<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $suspended_count ) ); ?></div>
			</div>
		</div>

		<div class="bn-segment bn-members-segment" role="group" aria-label="<?php esc_attr_e( 'Filter members by status', 'buddynext' ); ?>">
			<?php foreach ( $tab_links as $key => $link ) : ?>
				<?php $is_active = ( $status === $key ); ?>
				<a href="<?php echo esc_url( $link['url'] ); ?>"
					class="bn-segment__item<?php echo $is_active ? ' is-active' : ''; ?>"
					aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
					<?php echo esc_html( $link['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</div>

		<div class="bn-settings-section bn-members-table-wrap">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Members', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ss-body">
			<form method="get"
				action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"
				class="bn-members-filter bn-admin-hub__form-bare"
				role="search">
				<input type="hidden" name="page" value="buddynext-members">
				<?php if ( 'all' !== $status ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<?php endif; ?>
				<label for="bn-members-search" class="screen-reader-text"><?php esc_html_e( 'Search members', 'buddynext' ); ?></label>
				<input type="search"
					id="bn-members-search"
					name="s"
					class="bn-input"
					placeholder="<?php esc_attr_e( 'Search by name, email or username...', 'buddynext' ); ?>"
					value="<?php echo esc_attr( $search ); ?>">
				<label for="bn-members-role" class="screen-reader-text"><?php esc_html_e( 'Filter by role', 'buddynext' ); ?></label>
				<select id="bn-members-role" name="role" class="bn-select">
					<option value=""><?php esc_html_e( 'All Roles', 'buddynext' ); ?></option>
					<?php foreach ( wp_roles()->get_names() as $rk => $rl ) : ?>
						<option value="<?php echo esc_attr( $rk ); ?>" <?php selected( $role_filter, $rk ); ?>>
							<?php echo esc_html( translate_user_role( $rl ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="bn-btn" data-variant="secondary" data-size="sm">
					<?php esc_html_e( 'Filter', 'buddynext' ); ?>
				</button>
			</form>

			<div class="bn-table-wrap__scroll">
				<?php if ( empty( $members ) ) : ?>
					<p class="bn-members-empty"><?php esc_html_e( 'No members found.', 'buddynext' ); ?></p>
				<?php else : ?>
					<table class="bn-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Member', 'buddynext' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Email', 'buddynext' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Role', 'buddynext' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'buddynext' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Joined', 'buddynext' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Last Active', 'buddynext' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Last Login', 'buddynext' ); ?></th>
								<th scope="col" data-align="end"><?php esc_html_e( 'Actions', 'buddynext' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $members as $member ) : ?>
							<tr>
								<td>
									<div class="bn-member-cell">
										<div class="bn-avatar bn-avatar-initials <?php echo esc_attr( MemberDisplay::get_avatar_color( $member['id'] ) ); ?>" data-size="md" aria-hidden="true">
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
										<span class="bn-badge" data-tone="danger"><?php esc_html_e( 'Suspended', 'buddynext' ); ?></span>
									<?php elseif ( ! empty( $member['pending_approval'] ) ) : ?>
										<span class="bn-badge" data-tone="warn"><?php esc_html_e( 'Pending Approval', 'buddynext' ); ?></span>
									<?php else : ?>
										<span class="bn-badge" data-tone="success"><?php esc_html_e( 'Active', 'buddynext' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="bn-col-muted">
									<time datetime="<?php echo esc_attr( gmdate( 'c', strtotime( $member['registered'] ) ) ); ?>">
										<?php echo esc_html( gmdate( 'M j, Y', strtotime( $member['registered'] ) ) ); ?>
									</time>
								</td>
								<td class="bn-col-muted">
									<?php if ( $member['last_active'] > 0 ) : ?>
										<time datetime="<?php echo esc_attr( gmdate( 'c', $member['last_active'] ) ); ?>">
											<?php echo esc_html( MemberDisplay::human_time_diff_short( $member['last_active'] ) ); ?>
										</time>
									<?php else : ?>
										<span aria-hidden="true">&mdash;</span>
										<span class="screen-reader-text"><?php esc_html_e( 'Never', 'buddynext' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="bn-col-muted">
									<?php if ( $member['last_login'] > 0 ) : ?>
										<time datetime="<?php echo esc_attr( gmdate( 'c', $member['last_login'] ) ); ?>">
											<?php echo esc_html( MemberDisplay::human_time_diff_short( $member['last_login'] ) ); ?>
										</time>
									<?php else : ?>
										<?php esc_html_e( 'Never', 'buddynext' ); ?>
									<?php endif; ?>
								</td>
								<td data-align="end">
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
										<a href="<?php echo esc_url( \BuddyNext\Core\PageRouter::profile_url( absint( $member['id'] ) ) ); ?>" class="bn-btn" data-variant="ghost" data-size="sm" target="_blank" rel="noopener">
											<?php esc_html_e( 'View', 'buddynext' ); ?>
										</a>
										<a href="<?php echo esc_url( $edit_url ); ?>" class="bn-btn" data-variant="ghost" data-size="sm">
											<?php esc_html_e( 'Edit', 'buddynext' ); ?>
										</a>
										<div class="bn-more-menu" data-uid="<?php echo absint( $member['id'] ); ?>">
											<button type="button" class="bn-more-btn" aria-haspopup="menu" aria-label="
											<?php
												/* translators: %s: member display name */
												echo esc_attr( sprintf( __( 'More actions for %s', 'buddynext' ), $member['display'] ) );
											?>
											">
												<?php echo \BuddyNext\Core\IconService::render( 'more-horizontal' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											</button>
											<div class="bn-more-dropdown" role="menu">
												<?php if ( $member['suspended'] ) : ?>
													<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
														<input type="hidden" name="action" value="bn_unsuspend_member">
														<input type="hidden" name="user_id" value="<?php echo absint( $member['id'] ); ?>">
														<?php wp_nonce_field( 'bn_unsuspend_member' ); ?>
														<button type="submit" class="bn-dropdown-item" role="menuitem">
															<?php esc_html_e( 'Unsuspend', 'buddynext' ); ?>
														</button>
													</form>
												<?php else : ?>
													<form method="post"
														action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
														data-bn-confirm="1"
														data-bn-confirm-reason="1"
														data-bn-confirm-title="<?php esc_attr_e( 'Suspend this member?', 'buddynext' ); ?>"
														data-bn-confirm-body="<?php /* translators: %s: member display name */ echo esc_attr( sprintf( __( 'Suspend %s? They will lose posting access until the suspension is lifted.', 'buddynext' ), $member['display'] ) ); ?>"
														data-bn-confirm-label="<?php esc_attr_e( 'Suspend member', 'buddynext' ); ?>">
														<input type="hidden" name="action" value="bn_suspend_member">
														<input type="hidden" name="user_id" value="<?php echo absint( $member['id'] ); ?>">
														<?php wp_nonce_field( 'bn_suspend_member' ); ?>
														<button type="submit" class="bn-dropdown-item bn-dropdown-danger" role="menuitem">
															<?php esc_html_e( 'Suspend', 'buddynext' ); ?>
														</button>
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
			</div>

			<?php
			$this->render_pagination(
				$page,
				(int) $pages,
				(int) $total,
				self::DEFAULT_PER_PAGE,
				static function ( int $p ) use ( $search, $status, $role_filter ): string {
					return add_query_arg(
						array_filter(
							array(
								'page'   => 'buddynext-members',
								'paged'  => $p > 1 ? $p : false,
								's'      => '' !== $search ? $search : false,
								'status' => 'all' !== $status ? $status : false,
								'role'   => '' !== $role_filter ? $role_filter : false,
							)
						),
						admin_url( 'admin.php' )
					);
				},
				__( 'Members pagination', 'buddynext' )
			);
			?>
			</div><!-- .bn-ss-body -->
		</div><!-- .bn-settings-section -->

		<?php $this->render_confirm_modal(); ?>
		<?php
	}

	/**
	 * Render the shared destructive-confirm modal scaffold.
	 *
	 * The modal is hidden until activated by a form carrying data-bn-confirm="1".
	 * JS in assets/js/admin/members.js wires open/close behaviour.
	 *
	 * @return void
	 */
	private function render_confirm_modal(): void {
		?>
		<div id="bn-members-confirm-modal" class="bn-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="bn-members-confirm-title" hidden>
			<div class="bn-modal__panel" data-tone="danger" data-size="sm">
				<div class="bn-modal__head">
					<h2 id="bn-members-confirm-title" class="bn-modal__title" data-bn-confirm-title>
						<?php esc_html_e( 'Confirm action', 'buddynext' ); ?>
					</h2>
					<button type="button" class="bn-modal__close" data-bn-confirm-cancel aria-label="<?php esc_attr_e( 'Close dialog', 'buddynext' ); ?>">
						<?php echo \BuddyNext\Core\IconService::render( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</button>
				</div>
				<div class="bn-modal__body" data-bn-confirm-body>
					<?php esc_html_e( 'Are you sure?', 'buddynext' ); ?>
				</div>
				<div class="bn-field bn-modal__reason" data-bn-confirm-reason-wrap hidden>
					<label class="bn-label" for="bn-members-confirm-reason"><?php esc_html_e( 'Reason (optional, shown in the moderation log)', 'buddynext' ); ?></label>
					<textarea id="bn-members-confirm-reason" class="bn-textarea" rows="3" data-bn-confirm-reason-field></textarea>
				</div>
				<div class="bn-modal__foot">
					<button type="button" class="bn-btn" data-variant="ghost" data-bn-confirm-cancel>
						<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
					</button>
					<button type="button" class="bn-btn" data-variant="danger" data-bn-confirm-accept>
						<?php esc_html_e( 'Confirm', 'buddynext' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}
}

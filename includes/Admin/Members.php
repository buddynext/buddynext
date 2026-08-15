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

use BuddyNext\Profile\Handle;
use BuddyNext\Admin\Members\MemberDisplay;

/**
 * Admin panel for managing BuddyNext community members.
 */
class Members extends AdminPageBase {

	/**
	 * Default items per page for member listing.
	 */
	private const DEFAULT_PER_PAGE = 20;

	/**
	 * The admin-post action for the one-click "repair unmentionable handles" button.
	 */
	private const ACTION_REPAIR_HANDLES = 'bn_repair_handles';

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bn_suspend_member', array( $this, 'handle_suspend' ) );
		add_action( 'admin_post_bn_unsuspend_member', array( $this, 'handle_unsuspend' ) );
		add_action( 'admin_post_bn_bulk_members', array( $this, 'handle_bulk' ) );
		add_action( 'admin_post_bn_save_member_profile', array( $this, 'handle_save_member_profile' ) );
		add_action( 'admin_post_bn_set_community_role', array( $this, 'handle_set_community_role' ) );
		add_action( 'buddynext_edit_member_sections', array( $this, 'render_community_role_section' ), 5, 1 );
		add_action( 'buddynext_admin_member_profile_saved', array( $this, 'save_community_role' ), 10, 1 );
		add_action( 'admin_post_' . self::ACTION_REPAIR_HANDLES, array( $this, 'handle_repair_handles' ) );
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
			'bn-admin-bulk-select',
			$plugin_url . 'assets/js/admin/bulk-select.js',
			array( 'wp-i18n' ),
			$version,
			true
		);
		wp_set_script_translations( 'bn-admin-bulk-select', 'buddynext', BUDDYNEXT_DIR . 'languages' );

		// Shared row "more" (kebab) dropdown wiring, reused by every admin list
		// page that renders a .bn-more-menu overflow cluster (Members, Spaces).
		wp_enqueue_script(
			'bn-admin-more-menu',
			$plugin_url . 'assets/js/admin/more-menu.js',
			array(),
			$version,
			true
		);

		wp_enqueue_script(
			'bn-admin-members',
			$plugin_url . 'assets/js/admin/members.js',
			array( 'wp-i18n', 'bn-admin-more-menu' ),
			$version,
			true
		);

		wp_set_script_translations( 'bn-admin-members', 'buddynext', BUDDYNEXT_DIR . 'languages' );

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
				   AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())"
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

		// Last-active ordering lives in bn_presence, which WP_User_Query cannot
		// reach: it orders over wp_users (plus usermeta) and knows nothing about
		// our tables. pre_user_query is the only seam that can add the join, and
		// it is added ONE-SHOT - registered immediately before this query and
		// removed immediately after - so it can never colour another
		// WP_User_Query later in the same request.
		$presence_sort = null;
		if ( 'last_active' === $orderby ) {
			$presence_sort = static function ( \WP_User_Query $q ) use ( $order ): void {
				global $wpdb;

				$q->query_from .= " LEFT JOIN {$wpdb->prefix}bn_presence pres ON pres.user_id = {$wpdb->users}.ID";

				// LEFT JOIN, not INNER: a member who has never been seen has no
				// presence row at all (13 of 214 on the dev site), and an INNER
				// join would silently drop most of the directory from this view.
				//
				// The expression is COPIED from the member directory
				// (Profile/MemberDirectoryService.php, sort=most_active) and must
				// stay identical to it. If the two diverge, the same member ranks
				// differently in the admin list than on the directory the owner is
				// looking at, which is worse than the sort being slightly slow.
				//
				// COALESCE makes the index on pres.last_active unusable, so this
				// filesorts the filtered set - accepted deliberately, same ruling
				// as the frontend: it is an opt-in, non-default sort. Measured at
				// 10k users: 6.5ms against 0.9ms for the default sort. If it ever
				// becomes the DEFAULT sort, denormalise last_active onto a row we
				// already scan instead of widening this join.
				$q->query_orderby = "ORDER BY COALESCE(pres.last_active, 0) {$order}, {$wpdb->users}.ID DESC";
			};

			add_action( 'pre_user_query', $presence_sort );
		}

		$user_query = new \WP_User_Query( $query_args );

		if ( null !== $presence_sort ) {
			remove_action( 'pre_user_query', $presence_sort );
		}

		$result_ids = wp_list_pluck( $user_query->get_results(), 'ID' );

		// Pre-fetch suspended status for every user in this page in one query
		// so the foreach below never issues a per-row DB round-trip.
		$suspended_set = array();
		$presence_map  = array();
		if ( ! empty( $result_ids ) ) {
			global $wpdb;
			$int_ids      = array_map( 'intval', $result_ids );
			$placeholders = implode( ',', array_fill( 0, count( $int_ids ), '%d' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$suspended_set = array_flip(
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT user_id FROM {$wpdb->prefix}bn_user_suspensions WHERE user_id IN ({$placeholders}) AND lifted_at IS NULL AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())",
						...$int_ids
					)
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			// Prime usermeta cache for this batch — prevents extra queries during
			// avatar rendering and any meta reads that follow in template loops.
			update_meta_cache( 'user', $result_ids );

			// Batch presence in one indexed read (was a per-row bn_last_active meta
			// lookup) so the loop below issues no per-row presence query.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$presence_rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, last_active FROM {$wpdb->prefix}bn_presence WHERE user_id IN ({$placeholders})",
					...$int_ids
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			foreach ( $presence_rows as $pr ) {
				$presence_map[ (int) $pr['user_id'] ] = (int) $pr['last_active'];
			}
		}

		$members = array();
		foreach ( $user_query->get_results() as $user ) {
			$members[] = array(
				'id'               => $user->ID,
				'login'            => $user->user_login,
				// The mentionable handle is user_nicename, NOT user_login: mentions
				// resolve get_user_by('slug'), and the two differ whenever a login
				// held a space, a capital or an email ("Brendan Smith" ->
				// "brendan-smith"). Showing the login here handed the owner a handle
				// that does not work when typed.
				'handle'           => \BuddyNext\Core\PageRouter::member_handle( (int) $user->ID ),
				'email'            => $user->user_email,
				'display'          => $user->display_name,
				'registered'       => $user->user_registered,
				'suspended'        => isset( $suspended_set[ $user->ID ] ),
				'pending_approval' => (bool) get_user_meta( $user->ID, 'bn_pending_approval', true ),
				'role'             => ( (array) $user->roles )[0] ?? 'subscriber',
				'last_active'      => $presence_map[ $user->ID ] ?? 0,
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
	 * Writes a row to bn_user_suspensions (hide_posts=0 — an action restriction
	 * that leaves the member's existing content visible) and fires both
	 * buddynext_user_suspended (canonical — EventListener listens here for
	 * email/notification dispatch) and the legacy buddynext_member_suspended hook
	 * for any third-party listeners.
	 *
	 * The bn_user_suspensions table is the single source of truth for suspension
	 * state; the old bn_suspended usermeta was retired (it created a split-brain
	 * where admin-panel suspensions hid content that REST/queue ones did not).
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $reason  Optional reason recorded with the suspension.
	 * @return void
	 */
	public function suspend_member( int $user_id, string $reason = '' ): void {
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
	 * Marks the most-recent active bn_user_suspensions row as lifted and fires
	 * both buddynext_user_unsuspended (canonical) and the legacy
	 * buddynext_member_unsuspended hook. (The retired bn_suspended usermeta is no
	 * longer written or read; a one-time upgrade step clears any stray values.)
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public function unsuspend_member( int $user_id ): void {
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
		 * Fires with BOTH arguments. ModerationService fires this with ( $user_id, $actor_id );
		 * this site used to pass $user_id alone. WordPress gives a callback only as many
		 * arguments as the firing site supplied, so a listener registered with the documented 2
		 * args and a typed signature took an ArgumentCountError — a fatal — whenever a
		 * suspension was lifted from wp-admin rather than through moderation. The arity is part
		 * of the contract and must not vary by call site.
		 *
		 * @param int $user_id  Unsuspended user ID.
		 * @param int $actor_id User who lifted the suspension.
		 */
		do_action( 'buddynext_member_unsuspended', $user_id, get_current_user_id() );
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
				'fields'  => array( 'ID', 'user_login', 'user_nicename', 'user_email', 'user_registered' ),
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
	 * Render the Community Role section on the edit-member screen.
	 *
	 * Community role (member < moderator < admin) is a BuddyNext-level fact about
	 * the member, like Member Type and Membership, so it belongs beside them in
	 * the Account tab rather than as an inline control in the directory. It saves
	 * with the profile — one Save button for the whole screen.
	 *
	 * @param int $user_id Member being edited.
	 * @return void
	 */
	public function render_community_role_section( int $user_id ): void {
		if ( ! current_user_can( 'manage_options' ) || $user_id <= 0 ) {
			return;
		}

		$svc     = buddynext_service( 'roles' );
		$current = ( is_object( $svc ) && method_exists( $svc, 'get_role' ) )
			? (string) $svc->get_role( $user_id )
			: 'member';

		$choices = array(
			'member'    => __( 'Member', 'buddynext' ),
			'moderator' => __( 'Moderator', 'buddynext' ),
			'admin'     => __( 'Admin', 'buddynext' ),
		);
		?>
		<div class="bn-settings-section bn-community-role-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Community Role', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ss-body">
				<div class="bn-field-row">
					<div class="bn-label"><label for="bn-community-role"><?php esc_html_e( 'Role', 'buddynext' ); ?></label></div>
					<div class="bn-control">
						<select id="bn-community-role" name="bn_community_role" class="bn-select">
							<?php foreach ( $choices as $bn_value => $bn_label ) : ?>
								<option value="<?php echo esc_attr( $bn_value ); ?>" <?php selected( $current, $bn_value ); ?>>
									<?php echo esc_html( $bn_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<span class="bn-field-hint">
							<?php esc_html_e( 'Controls moderation powers inside the community. Separate from the WordPress role above. Saved with the profile.', 'buddynext' ); ?>
						</span>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Persist the community role from the profile submit.
	 *
	 * Nonce and capability were verified by handle_save_member_profile() before
	 * this action fired. Only this section's own key is read.
	 *
	 * @param int $user_id Member that was saved.
	 * @return void
	 */
	public function save_community_role( int $user_id ): void {
		if ( $user_id <= 0 || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by handle_save_member_profile() before this action fires.
		if ( ! isset( $_POST['bn_community_role'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
		$role = sanitize_key( wp_unslash( $_POST['bn_community_role'] ) );

		if ( ! in_array( $role, array( 'member', 'moderator', 'admin' ), true ) ) {
			return;
		}

		$svc = buddynext_service( 'roles' );

		if ( is_object( $svc ) && method_exists( $svc, 'set_role' ) ) {
			$svc->set_role( $user_id, $role );
		}
	}

	/**
	 * Print a community-role badge for the directory.
	 *
	 * @param string $role Community role slug.
	 * @return void
	 */
	private static function render_community_role_badge( string $role ): void {
		$labels = array(
			'admin'     => __( 'Admin', 'buddynext' ),
			'moderator' => __( 'Moderator', 'buddynext' ),
			'member'    => __( 'Member', 'buddynext' ),
		);

		$label = $labels[ $role ] ?? $labels['member'];

		echo '<span class="bn-badge bn-badge-crole-' . esc_attr( $role ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Handle admin_post_bn_set_community_role — set a member's BuddyNext community
	 * role (member | moderator | admin).
	 *
	 * Retained as the write path for the front-end Community Admin > Members
	 * control, which posts here. The wp-admin directory no longer does: assigning
	 * a community role moved to the member edit screen, where it saves with the
	 * profile alongside Member Type, Membership and Labels.
	 *
	 * @return void
	 */
	public function handle_set_community_role(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}

		check_admin_referer( 'bn_set_community_role' );

		$user_id = absint( wp_unslash( $_POST['user_id'] ?? 0 ) );
		$role    = sanitize_key( (string) wp_unslash( $_POST['community_role'] ?? '' ) );

		if ( $user_id > 0 && in_array( $role, array( 'member', 'moderator', 'admin' ), true ) ) {
			$roles = buddynext_service( 'roles' );
			if ( is_object( $roles ) && method_exists( $roles, 'set_role' ) ) {
				$roles->set_role( $user_id, $role );
			}
		}

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=buddynext-members' ) );
		exit;
	}

	/**
	 * Handle admin_post_bn_bulk_members — apply a bulk action to the selected
	 * member IDs (checkbox column). Reuses the same suspend/unsuspend service
	 * calls the single-row actions use; self and other admins are skipped.
	 *
	 * @return void
	 */
	public function handle_bulk(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}

		check_admin_referer( 'bn_bulk_members' );

		$bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each element is cast via array_map( 'absint' ) on the next line.
		$raw_ids = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array();
		$ids     = array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) );

		$current = get_current_user_id();
		$done    = 0;
		if ( '' !== $bulk_action && ! empty( $ids ) ) {
			foreach ( $ids as $uid ) {
				// Never let a bulk action hit yourself or another administrator.
				if ( $uid === $current || user_can( $uid, 'manage_options' ) ) {
					continue;
				}
				if ( 'suspend' === $bulk_action ) {
					$this->suspend_member( $uid, '' );
					++$done;
				} elseif ( 'unsuspend' === $bulk_action ) {
					$this->unsuspend_member( $uid );
					++$done;
				}
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'buddynext-members',
					'bulk_action' => $bulk_action,
					'bulk_done'   => $done,
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
			( new \BuddyNext\Profile\AvatarService() )->delete_cover( (int) $user_id );
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
				( new \BuddyNext\Profile\AvatarService() )->save_cover_url( (int) $user_id, (string) $cover_uploaded );
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

		// Handle email update. A bad or already-taken email must surface an error
		// rather than silently skipping and reporting "saved" (same early-exit
		// pattern the slug-taken check below uses).
		if ( isset( $_POST['bn_user_email'] ) && '' !== $_POST['bn_user_email'] ) {
			$new_email = sanitize_email( wp_unslash( $_POST['bn_user_email'] ) );
			if ( '' !== $new_email && $new_email !== $wp_user->user_email ) {
				if ( ! is_email( $new_email ) ) {
					wp_safe_redirect( add_query_arg( 'bn_error', 'email_invalid', $redirect_url ) );
					exit;
				}
				$existing_owner = email_exists( $new_email );
				if ( false !== $existing_owner && (int) $existing_owner !== $user_id ) {
					wp_safe_redirect( add_query_arg( 'bn_error', 'email_taken', $redirect_url ) );
					exit;
				}
				wp_update_user(
					array(
						'ID'         => $user_id,
						'user_email' => $new_email,
					)
				);
			}
		}

		// Handle role update. An invalid role must surface an error rather than
		// silently skipping and reporting success.
		if ( isset( $_POST['bn_user_role'] ) && '' !== $_POST['bn_user_role'] ) {
			$new_role    = sanitize_key( wp_unslash( $_POST['bn_user_role'] ) );
			$valid_roles = array_keys( wp_roles()->get_names() );
			if ( ! in_array( $new_role, $valid_roles, true ) ) {
				wp_safe_redirect( add_query_arg( 'bn_error', 'role_invalid', $redirect_url ) );
				exit;
			}
			$user_obj = new \WP_User( $user_id );
			$user_obj->set_role( $new_role );
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
	/**
	 * Warn the owner when members exist that nobody can mention.
	 *
	 * A `user_nicename` holding characters the mention parsers stop at — an email
	 * address, typically, written straight into the column by a migration — makes
	 * that member silently unmentionable. Their profile still loads, so nothing
	 * looks broken; the fault only surfaces when somebody reports that a member
	 * "does not come up". This is the only place the owner can find out without
	 * being told.
	 *
	 * Counted with one COUNT(*), and cached: the condition changes only when users
	 * are imported, so re-scanning on every render would tax a large roster for an
	 * answer that is almost always zero. SQL narrows to candidates and PHP decides,
	 * because a MySQL character range answers to collation while the parsers run
	 * PCRE — the two can disagree, and PCRE is the one that matters.
	 *
	 * @return void
	 */
	private function render_unmentionable_handles_notice(): void {
		$count = ( new \BuddyNext\Profile\HandleRepair() )->count_unsafe();

		if ( $count < 1 ) {
			// A just-completed repair leaves a one-request success flag.
			$this->maybe_render_repair_result();
			return;
		}

		$title = sprintf(
			/* translators: %d: number of members. */
			_n(
				'%d member cannot be mentioned',
				'%d members cannot be mentioned',
				$count,
				'buddynext'
			),
			$count
		);

		$repair_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_REPAIR_HANDLES ),
			self::ACTION_REPAIR_HANDLES
		);
		?>
		<div class="bn-alert">
			<span class="bn-alert__icon" aria-hidden="true"><?php buddynext_icon( 'alert-triangle' ); ?></span>
			<div class="bn-alert__body">
				<p class="bn-alert__title"><?php echo esc_html( $title ); ?></p>
				<p class="bn-alert__text">
					<?php esc_html_e( 'Their profile address contains characters that mentions do not support, usually from an import. Repairing normalises it to a mentionable form. The affected members\' profile addresses will change.', 'buddynext' ); ?>
				</p>
			</div>
			<span class="bn-alert__action">
				<a class="button button-primary" href="<?php echo esc_url( $repair_url ); ?>">
					<?php esc_html_e( 'Repair handles', 'buddynext' ); ?>
				</a>
			</span>
		</div>
		<?php
	}

	/**
	 * Show the outcome of a just-completed repair, once.
	 *
	 * The handler stashes a short-lived flag and redirects here, so the owner sees
	 * "repaired N" on the screen they clicked from rather than a silent reload.
	 *
	 * @return void
	 */
	private function maybe_render_repair_result(): void {
		$result = get_transient( 'bn_handle_repair_result_' . get_current_user_id() );
		if ( false === $result || ! is_array( $result ) ) {
			return;
		}

		delete_transient( 'bn_handle_repair_result_' . get_current_user_id() );

		$repaired = (int) ( $result['repaired'] ?? 0 );
		$skipped  = (int) ( $result['skipped'] ?? 0 );

		if ( $repaired < 1 && $skipped < 1 ) {
			return;
		}

		$msg = sprintf(
			/* translators: %d: number of members repaired. */
			_n( 'Repaired %d member handle.', 'Repaired %d member handles.', $repaired, 'buddynext' ),
			$repaired
		);

		if ( $skipped > 0 ) {
			$msg .= ' ' . sprintf(
				/* translators: %d: number of members skipped. */
				_n(
					'%d could not be repaired automatically and needs a handle set by hand.',
					'%d could not be repaired automatically and need handles set by hand.',
					$skipped,
					'buddynext'
				),
				$skipped
			);
		}

		printf(
			'<div class="bn-notice %s">%s</div>',
			esc_attr( $skipped > 0 ? 'bn-notice-error' : 'bn-notice-success' ),
			esc_html( $msg )
		);
	}

	/**
	 * Handle the one-click "repair unmentionable handles" button.
	 *
	 * Shares HandleRepair with the WP-CLI command, so the button and
	 * `wp buddynext handles repair` do exactly the same thing — this is the
	 * owner-facing door for site owners who do not have shell access, which is
	 * most of them.
	 *
	 * @return void
	 */
	public function handle_repair_handles(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'buddynext' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_REPAIR_HANDLES );

		$result = ( new \BuddyNext\Profile\HandleRepair() )->repair_all();

		set_transient(
			'bn_handle_repair_result_' . get_current_user_id(),
			array(
				'repaired' => (int) $result['repaired'],
				'skipped'  => (int) $result['skipped'],
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=buddynext-members' ) );
		exit;
	}

	/**
	 * The members roster tab.
	 *
	 * @return void
	 */
	private function render_members_tab(): void {
		$this->render_unmentionable_handles_notice();

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

		// Sort: default newest-joined; ?orderby=display_name lists the roster
		// alphabetically (C5 — sortability parity with Spaces:Directory), and
		// ?orderby=last_active ranks by presence. The first two are ordered
		// natively by WP_User_Query; last_active is served by the bn_presence
		// join in list_members(), which is why it is safe to admit here.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bn_orderby = sanitize_key( wp_unslash( $_GET['orderby'] ?? 'registered' ) );
		if ( ! in_array( $bn_orderby, array( 'registered', 'display_name', 'last_active' ), true ) ) {
			$bn_orderby = 'registered';
		}

		// Direction, for the columns that support both. Descending is the
		// meaningful default everywhere here: newest joined, and most recently
		// active. Ascending on last_active is a real admin need rather than a
		// mirror-image curiosity - it surfaces dormant and never-seen accounts
		// first, which is how an owner finds them.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bn_order = strtoupper( sanitize_key( wp_unslash( $_GET['order'] ?? 'DESC' ) ) );
		$bn_order = 'ASC' === $bn_order ? 'ASC' : 'DESC';

		$data    = $this->list_members(
			array(
				'page'    => $page,
				'search'  => $search,
				'status'  => $status,
				'role'    => $role_filter,
				'orderby' => $bn_orderby,
				// A-Z reads ascending; last_active honours the toggle so an owner
				// can flip to dormant-first; joined stays newest-first.
				'order'   => 'display_name' === $bn_orderby ? 'ASC' : ( 'last_active' === $bn_orderby ? $bn_order : 'DESC' ),
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

		// Active = seen in the last 30 days (bn_presence, the same source as
		// the roster's Last Active column). The old Total-minus-suspended math
		// read 1,530/1,530 forever on a healthy site - a stat that never moves
		// tells the owner nothing.
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$active_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_presence WHERE last_active >= %d",
				time() - ( 30 * DAY_IN_SECONDS )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = sanitize_key( wp_unslash( $_GET['action'] ?? '' ) );
		if ( 'suspended' === $action ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Member suspended.', 'buddynext' ) . '</p></div>';
		} elseif ( 'unsuspended' === $action ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Member unsuspended.', 'buddynext' ) . '</p></div>';
		}

		// Bulk-action result. handle_bulk() redirects with bulk_action + bulk_done;
		// previously neither was read, so a bulk op (including one that updated
		// nothing because every target was an admin/self) gave no feedback at all.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bulk_action_done = sanitize_key( wp_unslash( $_GET['bulk_action'] ?? '' ) );
		if ( '' !== $bulk_action_done && isset( $_GET['bulk_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a post-redirect notice flag; the state-changing bulk action in handle_bulk() verifies its own nonce.
			$bulk_done = absint( wp_unslash( $_GET['bulk_done'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $bulk_done > 0 ) {
				$bulk_msg = 'suspend' === $bulk_action_done
					/* translators: %d: number of members. */
					? sprintf( _n( '%d member suspended.', '%d members suspended.', $bulk_done, 'buddynext' ), $bulk_done )
					/* translators: %d: number of members. */
					: sprintf( _n( '%d member unsuspended.', '%d members unsuspended.', $bulk_done, 'buddynext' ), $bulk_done );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $bulk_msg ) . '</p></div>';
			} else {
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'No members were updated. Administrators and your own account are skipped from bulk actions.', 'buddynext' ) . '</p></div>';
			}
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
				<div class="bn-stat__label"><?php esc_html_e( 'Active (30 days)', 'buddynext' ); ?></div>
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
			<?php
			// One toolbar row: the search/filter form and the bulk-action form are
			// SIBLINGS in a single flex container. They used to live in different
			// parts of the DOM (the bulk form even sat inside the table's scroll
			// container), so they stacked as two misaligned rows with independent
			// sizing and the bulk bar scrolled sideways with the table.
			?>
			<div class="bn-members-toolbar">
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

				<?php if ( ! empty( $members ) ) : ?>
					<?php
					// Bulk-action form. The per-row checkboxes associate with it via
					// the form="bn-members-bulk" attribute, so they are NOT nested
					// inside the existing per-row action forms (invalid HTML) — which
					// also lets this form live in the toolbar, outside the scroll wrap.
					?>
					<form id="bn-members-bulk" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-bulk-bar">
						<input type="hidden" name="action" value="bn_bulk_members">
						<?php wp_nonce_field( 'bn_bulk_members' ); ?>
						<label for="bn-members-bulk-action" class="screen-reader-text"><?php esc_html_e( 'Bulk action', 'buddynext' ); ?></label>
						<select id="bn-members-bulk-action" name="bulk_action" class="bn-select" data-size="sm">
							<option value=""><?php esc_html_e( 'Bulk actions', 'buddynext' ); ?></option>
							<option value="suspend"><?php esc_html_e( 'Suspend', 'buddynext' ); ?></option>
							<option value="unsuspend"><?php esc_html_e( 'Unsuspend', 'buddynext' ); ?></option>
						</select>
						<button type="submit" class="bn-btn" data-variant="secondary" data-size="sm"><?php esc_html_e( 'Apply', 'buddynext' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<div class="bn-table-wrap__scroll">
				<?php if ( empty( $members ) ) : ?>
					<div class="bn-empty">
					<p class="bn-empty__title"><?php esc_html_e( 'No members found', 'buddynext' ); ?></p>
					<p class="bn-empty__sub"><?php esc_html_e( 'Try a different search or filter.', 'buddynext' ); ?></p>
				</div>
				<?php else : ?>
					<table class="bn-table" data-bn-bulk="bn-members-bulk">
						<thead>
							<tr>
								<th scope="col" class="bn-table__cb" data-align="center">
									<input type="checkbox" id="bn-members-cb-all" aria-label="<?php esc_attr_e( 'Select all members', 'buddynext' ); ?>">
								</th>
								<th scope="col" class="column-primary"><a href="<?php echo esc_url( add_query_arg( 'orderby', 'display_name' ) ); ?>" class="bn-th-sort<?php echo 'display_name' === $bn_orderby ? ' is-active' : ''; ?>"><?php esc_html_e( 'Member', 'buddynext' ); ?></a></th>
								<th scope="col"><?php esc_html_e( 'Role', 'buddynext' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Community role', 'buddynext' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'buddynext' ); ?></th>
								<th scope="col" class="bn-col-muted"><a href="<?php echo esc_url( remove_query_arg( 'orderby' ) ); ?>" class="bn-th-sort<?php echo 'registered' === $bn_orderby ? ' is-active' : ''; ?>"><?php esc_html_e( 'Joined', 'buddynext' ); ?></a></th>
								<?php
								// Clicking the active column flips direction, so the owner can
								// go from most-recently-active to dormant-first without a second
								// control. aria-sort belongs on the column header itself, not on
								// the link inside it.
								$bn_la_active = 'last_active' === $bn_orderby;
								$bn_la_next   = ( $bn_la_active && 'DESC' === $bn_order ) ? 'ASC' : 'DESC';
								?>
								<th scope="col" class="bn-col-muted"
									<?php
									if ( $bn_la_active ) :
										?>
										aria-sort="<?php echo esc_attr( 'ASC' === $bn_order ? 'ascending' : 'descending' ); ?>"<?php endif; ?>>
									<a href="
									<?php
									echo esc_url(
										add_query_arg(
											array(
												'orderby' => 'last_active',
												'order'   => $bn_la_next,
											)
										)
									);
									?>
												"
										class="bn-th-sort<?php echo $bn_la_active ? ' is-active' : ''; ?>">
										<?php esc_html_e( 'Last Active', 'buddynext' ); ?>
									</a>
								</th>
								<th scope="col" data-align="end"><?php esc_html_e( 'Actions', 'buddynext' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $members as $member ) : ?>
							<tr>
								<td class="bn-table__cb" data-align="center">
									<input type="checkbox" name="ids[]" form="bn-members-bulk" value="<?php echo absint( $member['id'] ); ?>" class="bn-bulk-cb" aria-label="
									<?php
										/* translators: %s: name of the item being selected. */
										echo esc_attr( sprintf( __( 'Select %s', 'buddynext' ), $member['display'] ) );
									?>
									">
								</td>
								<td class="column-primary" data-colname="<?php esc_attr_e( 'Member', 'buddynext' ); ?>">
									<div class="bn-member-cell">
										<div class="bn-avatar bn-avatar-initials <?php echo esc_attr( MemberDisplay::get_avatar_color( $member['id'] ) ); ?>" data-size="md" aria-hidden="true">
											<?php echo esc_html( MemberDisplay::get_initials( $member['display'] ) ); ?>
										</div>
										<div class="bn-member-info">
											<div class="bn-member-name"><?php echo esc_html( $member['display'] ); ?></div>
											<?php
											/*
											 * Handle and email share one line under the name. Email had its own
											 * column, which spent a full column of width repeating one fact per row
											 * and pushed Status / Joined / Last Active off-screen on a normal window.
											 * Identity reads better as one block: who they are, what they are called,
											 * how to reach them.
											 */
											?>
											<div class="bn-member-meta">
												<span class="bn-member-username">@<?php echo esc_html( $member['handle'] ); ?></span>
												<span class="bn-member-sep" aria-hidden="true">&middot;</span>
												<a class="bn-member-email" href="<?php echo esc_url( 'mailto:' . $member['email'] ); ?>"><?php echo esc_html( $member['email'] ); ?></a>
											</div>
										</div>
										<button type="button" class="toggle-row">
											<?php buddynext_icon( 'chevron-down', 'bn-toggle-row__icon' ); ?>
											<span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'buddynext' ); ?></span>
										</button>
									</div>
								</td>
								<td data-colname="<?php esc_attr_e( 'Role', 'buddynext' ); ?>"><?php MemberDisplay::render_role_badge( $member['role'] ); ?></td>
								<td data-colname="<?php esc_attr_e( 'Community role', 'buddynext' ); ?>">
									<?php
									// bn_community_role (member < moderator < admin). get_user_meta is
									// served from the batch cache list_members() already primed, so this
									// is not a per-row query. Same write path as the front-end panel:
									// admin_post_bn_set_community_role -> RoleService::set_role.
									$bn_roles_svc = buddynext_service( 'roles' );
									$bn_comm_role = ( is_object( $bn_roles_svc ) && method_exists( $bn_roles_svc, 'get_role' ) )
										? $bn_roles_svc->get_role( absint( $member['id'] ) )
										: 'member';
									?>
									<?php
									/*
									 * Display only. This cell used to carry a select plus an Update button —
									 * 80px of form in every row, which forced the whole table to 105px rows
									 * against ~20px of content and pushed the later columns off-screen.
									 * Assigning a community role now lives on the member edit screen beside
									 * Member Type, Membership and Labels, so the directory can go back to
									 * being scannable. Read as a badge, matching the Role column beside it.
									 */
									self::render_community_role_badge( $bn_comm_role );
									?>
								</td>
								<td data-colname="<?php esc_attr_e( 'Status', 'buddynext' ); ?>">
									<?php if ( $member['suspended'] ) : ?>
										<span class="bn-badge" data-tone="danger"><?php esc_html_e( 'Suspended', 'buddynext' ); ?></span>
									<?php elseif ( ! empty( $member['pending_approval'] ) ) : ?>
										<span class="bn-badge" data-tone="warn"><?php esc_html_e( 'Pending Approval', 'buddynext' ); ?></span>
									<?php else : ?>
										<span class="bn-badge" data-tone="success"><?php esc_html_e( 'Active', 'buddynext' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="bn-col-muted" data-colname="<?php esc_attr_e( 'Joined', 'buddynext' ); ?>">
									<time datetime="<?php echo esc_attr( gmdate( 'c', strtotime( $member['registered'] ) ) ); ?>">
										<?php echo esc_html( gmdate( 'M j, Y', strtotime( $member['registered'] ) ) ); ?>
									</time>
								</td>
								<td class="bn-col-muted" data-colname="<?php esc_attr_e( 'Last Active', 'buddynext' ); ?>">
									<?php if ( $member['last_active'] > 0 ) : ?>
										<time datetime="<?php echo esc_attr( gmdate( 'c', $member['last_active'] ) ); ?>">
											<?php echo esc_html( MemberDisplay::human_time_diff_short( $member['last_active'] ) ); ?>
										</time>
									<?php else : ?>
										<span aria-hidden="true">&mdash;</span>
										<span class="screen-reader-text"><?php esc_html_e( 'Never', 'buddynext' ); ?></span>
									<?php endif; ?>
								</td>
								<td data-align="end" data-colname="<?php esc_attr_e( 'Actions', 'buddynext' ); ?>">
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
										<a href="<?php echo esc_url( \BuddyNext\Core\PageRouter::profile_url( absint( $member['id'] ) ) ); ?>" class="bn-btn" data-variant="secondary" data-size="sm" target="_blank" rel="noopener">
											<?php esc_html_e( 'View', 'buddynext' ); ?>
										</a>
										<a href="<?php echo esc_url( $edit_url ); ?>" class="bn-btn" data-variant="secondary" data-size="sm">
											<?php esc_html_e( 'Edit', 'buddynext' ); ?>
										</a>
										<div class="bn-more-menu" data-uid="<?php echo absint( $member['id'] ); ?>">
											<button type="button" class="bn-more-btn" aria-haspopup="menu" aria-label="
											<?php
												/* translators: %s: name of the item the actions apply to. */
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
														data-bn-confirm-body="<?php /* translators: %s: member display name. */ echo esc_attr( sprintf( __( 'Suspend %s? They will lose posting access until the suspension is lifted.', 'buddynext' ), $member['display'] ) ); ?>"
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
				static function ( int $p ) use ( $search, $status, $role_filter, $bn_orderby, $bn_order ): string {
					return add_query_arg(
						array_filter(
							array(
								'page'    => 'buddynext-members',
								'paged'   => $p > 1 ? $p : false,
								's'       => '' !== $search ? $search : false,
								'status'  => 'all' !== $status ? $status : false,
								'role'    => '' !== $role_filter ? $role_filter : false,
								'orderby' => 'registered' !== $bn_orderby ? $bn_orderby : false,
								// Carry the direction too, or paging a dormant-first list
								// silently flips it back to most-recent on page 2.
								'order'   => 'DESC' !== $bn_order ? $bn_order : false,
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

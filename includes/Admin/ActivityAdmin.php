<?php
/**
 * Admin Activity screen — browse, search, filter, edit and delete community activity.
 *
 * BuddyNext registers many admin screens but none listed the activity feed, so a
 * post that broke the feed (and that nobody reported) was unreachable from
 * wp-admin: the only delete path was the front end, as the author. This screen
 * closes that gap with a time-ordered, paginated, filterable list backed by
 * PostService::admin_query() (big-site: LIMIT/OFFSET + COUNT, indexed filters,
 * batched author/space lookups). Delete reuses the moderation-capable
 * PostService::delete(); edit reuses PostService::update() (which now allows a
 * site admin / space moderator to redact another member's post).
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

use BuddyNext\Contracts\ListenerInterface;
use BuddyNext\Core\PageRouter;

/**
 * Registers the Engagement > Activity admin tab and its row actions.
 */
class ActivityAdmin implements ListenerInterface {

	/**
	 * Rows per page.
	 */
	private const PER_PAGE = 25;

	/**
	 * Types offered in the filter, in the order they appear. Kept to the ones a
	 * mainstream community actually produces; an unlisted type still lists, it is
	 * just not a filter shortcut.
	 *
	 * @var array<int,string>
	 */
	private const FILTER_TYPES = array( 'text', 'photo', 'media', 'link', 'poll', 'share', 'announcement', 'discussion' );

	/**
	 * Statuses offered in the filter.
	 *
	 * @var array<int,string>
	 */
	private const FILTER_STATUSES = array( 'published', 'scheduled', 'pending', 'draft', 'deleted' );

	/**
	 * Wire the tab and the row-action handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		AdminHub::register_tab(
			'engagement',
			'activity',
			__( 'Activity', 'buddynext' ),
			array( $this, 'render_page' ),
			array(
				'subtitle' => __( 'Browse, search, and moderate everything posted to the community.', 'buddynext' ),
				'position' => 5,
			)
		);

		add_action( 'admin_post_bn_activity_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_bn_activity_edit', array( $this, 'handle_edit' ) );
	}

	/**
	 * Render the tab body: the edit panel when ?edit_post is set, else the list.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Success notices after a redirect from the handlers.
		$done = isset( $_GET['bn_done'] ) ? sanitize_key( wp_unslash( $_GET['bn_done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'deleted' === $done ) {
			AdminPageBase::render_notice( __( 'Activity deleted.', 'buddynext' ), 'success', false, array( 'data-bn-clear-param' => 'bn_done bn_msg' ) );
		} elseif ( 'edited' === $done ) {
			AdminPageBase::render_notice( __( 'Activity updated.', 'buddynext' ), 'success', false, array( 'data-bn-clear-param' => 'bn_done bn_msg' ) );
		} elseif ( 'error' === $done ) {
			$msg = isset( $_GET['bn_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bn_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			AdminPageBase::render_notice( '' !== $msg ? $msg : __( 'That action could not be completed.', 'buddynext' ), 'error', false, array( 'data-bn-clear-param' => 'bn_done bn_msg' ) );
		}

		$edit_id = isset( $_GET['edit_post'] ) ? absint( wp_unslash( $_GET['edit_post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $edit_id > 0 ) {
			$this->render_edit( $edit_id );
			return;
		}

		$this->render_list();
	}

	/**
	 * Render the filter bar, table and pagination.
	 *
	 * @return void
	 */
	private function render_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET filters; no state change.
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$type      = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$space_id  = isset( $_GET['space_id'] ) ? absint( wp_unslash( $_GET['space_id'] ) ) : 0;
		$date_from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$date_to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		$paged     = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$posts = buddynext_service( 'post_service' );
		$data  = $posts->admin_query(
			array(
				'search'    => $search,
				'type'      => $type,
				'status'    => $status,
				'space_id'  => $space_id,
				'date_from' => $date_from,
				'date_to'   => $date_to,
				'page'      => $paged,
				'per_page'  => self::PER_PAGE,
			)
		);

		$items = $data['items'];
		$total = $data['total'];
		$pages = (int) ceil( $total / self::PER_PAGE );

		// Batch author + space lookups so the row loop stays query-free.
		$this->prime_authors( $items );
		$space_names = $this->space_names( $items );

		$base_url = AdminHub::tab_url( 'engagement', 'activity' );
		?>
		<div class="bn-activity-toolbar">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="bn-activity-filter bn-admin-hub__form-bare" role="search">
				<input type="hidden" name="page" value="buddynext-engagement">
				<input type="hidden" name="tab" value="activity">
				<label for="bn-activity-search" class="screen-reader-text"><?php esc_html_e( 'Search activity', 'buddynext' ); ?></label>
				<input type="search" id="bn-activity-search" name="s" class="bn-input"
					placeholder="<?php esc_attr_e( 'Search content or author…', 'buddynext' ); ?>"
					value="<?php echo esc_attr( $search ); ?>">

				<label for="bn-activity-type" class="screen-reader-text"><?php esc_html_e( 'Filter by type', 'buddynext' ); ?></label>
				<select id="bn-activity-type" name="type" class="bn-select">
					<option value=""><?php esc_html_e( 'All types', 'buddynext' ); ?></option>
					<?php foreach ( self::FILTER_TYPES as $t ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>><?php echo esc_html( $this->type_label( $t ) ); ?></option>
					<?php endforeach; ?>
				</select>

				<label for="bn-activity-status" class="screen-reader-text"><?php esc_html_e( 'Filter by status', 'buddynext' ); ?></label>
				<select id="bn-activity-status" name="status" class="bn-select">
					<option value=""><?php esc_html_e( 'All statuses', 'buddynext' ); ?></option>
					<?php foreach ( self::FILTER_STATUSES as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( $this->status_label( $s ) ); ?></option>
					<?php endforeach; ?>
				</select>

				<label for="bn-activity-from" class="screen-reader-text"><?php esc_html_e( 'From date', 'buddynext' ); ?></label>
				<input type="date" id="bn-activity-from" name="from" class="bn-input" value="<?php echo esc_attr( $date_from ); ?>" title="<?php esc_attr_e( 'From date', 'buddynext' ); ?>">
				<label for="bn-activity-to" class="screen-reader-text"><?php esc_html_e( 'To date', 'buddynext' ); ?></label>
				<input type="date" id="bn-activity-to" name="to" class="bn-input" value="<?php echo esc_attr( $date_to ); ?>" title="<?php esc_attr_e( 'To date', 'buddynext' ); ?>">

				<button type="submit" class="bn-btn" data-variant="secondary" data-size="sm"><?php esc_html_e( 'Filter', 'buddynext' ); ?></button>
				<?php if ( '' !== $search || '' !== $type || '' !== $status || $space_id > 0 || '' !== $date_from || '' !== $date_to ) : ?>
					<a class="bn-btn" data-variant="ghost" data-size="sm" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Clear', 'buddynext' ); ?></a>
				<?php endif; ?>
			</form>

			<p class="bn-activity-count">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: number of activity items. */
						_n( '%s item', '%s items', $total, 'buddynext' ),
						number_format_i18n( $total )
					)
				);
				?>
			</p>
		</div>

		<div class="bn-table-wrap__scroll">
			<?php if ( empty( $items ) ) : ?>
				<div class="bn-empty">
					<p class="bn-empty__title"><?php esc_html_e( 'No activity found', 'buddynext' ); ?></p>
					<p class="bn-empty__sub"><?php esc_html_e( 'Try a different search, type, status or date range.', 'buddynext' ); ?></p>
				</div>
			<?php else : ?>
				<table class="bn-table">
					<thead>
						<tr>
							<th scope="col" class="column-primary"><?php esc_html_e( 'Author', 'buddynext' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Content', 'buddynext' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Space', 'buddynext' ); ?></th>
							<th scope="col" data-align="center"><?php esc_html_e( 'Engagement', 'buddynext' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'buddynext' ); ?></th>
							<th scope="col" class="bn-col-muted"><?php esc_html_e( 'Posted', 'buddynext' ); ?></th>
							<th scope="col" data-align="end"><?php esc_html_e( 'Actions', 'buddynext' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $items as $row ) :
							$pid       = (int) $row['id'];
							$author    = get_userdata( (int) $row['user_id'] );
							$sid       = (int) $row['space_id'];
							$permalink = PageRouter::post_url( $pid );
							?>
							<tr>
								<td class="column-primary" data-colname="<?php esc_attr_e( 'Author', 'buddynext' ); ?>">
									<div class="bn-activity-author">
										<?php echo get_avatar( (int) $row['user_id'], 32, '', '', array( 'class' => 'bn-activity-author__avatar' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar returns escaped markup. ?>
										<span class="bn-activity-author__meta">
											<strong><?php echo esc_html( $author ? $author->display_name : __( '(unknown)', 'buddynext' ) ); ?></strong>
											<?php if ( $author ) : ?>
												<span class="bn-col-muted">@<?php echo esc_html( $author->user_login ); ?></span>
											<?php endif; ?>
										</span>
									</div>
								</td>
								<td data-colname="<?php esc_attr_e( 'Content', 'buddynext' ); ?>">
									<span class="bn-badge" data-tone="<?php echo esc_attr( $this->type_tone( (string) $row['type'], (int) $row['is_announcement'] ) ); ?>"><?php echo esc_html( $this->type_label( (string) $row['type'] ) ); ?></span>
									<span class="bn-activity-preview"><?php echo esc_html( $this->preview( (string) $row['content'] ) ); ?></span>
								</td>
								<td data-colname="<?php esc_attr_e( 'Space', 'buddynext' ); ?>">
									<?php echo $sid > 0 && isset( $space_names[ $sid ] ) ? esc_html( $space_names[ $sid ] ) : '<span class="bn-col-muted">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal dash only. ?>
								</td>
								<td data-align="center" data-colname="<?php esc_attr_e( 'Engagement', 'buddynext' ); ?>">
									<span class="bn-col-muted" title="<?php esc_attr_e( 'Reactions · Comments · Shares', 'buddynext' ); ?>">
										<?php echo esc_html( number_format_i18n( (int) $row['reaction_count'] ) ); ?> ·
										<?php echo esc_html( number_format_i18n( (int) $row['comment_count'] ) ); ?> ·
										<?php echo esc_html( number_format_i18n( (int) $row['share_count'] ) ); ?>
									</span>
								</td>
								<td data-colname="<?php esc_attr_e( 'Status', 'buddynext' ); ?>">
									<span class="bn-badge" data-tone="<?php echo esc_attr( $this->status_tone( (string) $row['status'] ) ); ?>"><?php echo esc_html( $this->status_label( (string) $row['status'] ) ); ?></span>
								</td>
								<td class="bn-col-muted" data-colname="<?php esc_attr_e( 'Posted', 'buddynext' ); ?>">
									<time datetime="<?php echo esc_attr( (string) $row['created_at'] ); ?>" title="<?php echo esc_attr( (string) $row['created_at'] ); ?>">
										<?php echo esc_html( $this->ago( (string) $row['created_at'] ) ); ?>
									</time>
								</td>
								<td data-align="end" data-colname="<?php esc_attr_e( 'Actions', 'buddynext' ); ?>">
									<div class="bn-activity-actions">
										<a class="bn-btn" data-variant="ghost" data-size="sm" href="<?php echo esc_url( $permalink ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'buddynext' ); ?></a>
										<a class="bn-btn" data-variant="ghost" data-size="sm" href="<?php echo esc_url( add_query_arg( 'edit_post', $pid, $base_url ) ); ?>"><?php esc_html_e( 'Edit', 'buddynext' ); ?></a>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-activity-actions__del">
											<input type="hidden" name="action" value="bn_activity_delete">
											<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $pid ); ?>">
											<?php wp_nonce_field( 'bn_activity_delete_' . $pid ); ?>
											<button type="submit" class="bn-btn" data-variant="danger" data-size="sm"
												data-bn-confirm="<?php esc_attr_e( 'This permanently removes the post and its reactions, comments and votes. This cannot be undone.', 'buddynext' ); ?>"
												data-bn-confirm-tone="danger"
												data-bn-confirm-title="<?php esc_attr_e( 'Delete this activity?', 'buddynext' ); ?>"
												data-bn-confirm-ok="<?php esc_attr_e( 'Delete', 'buddynext' ); ?>"
												data-bn-confirm-cancel="<?php esc_attr_e( 'Cancel', 'buddynext' ); ?>">
												<?php esc_html_e( 'Delete', 'buddynext' ); ?>
											</button>
										</form>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<?php
		// Shared windowed paginator (summary + numbered window), the same control
		// the Members directory and every other admin list uses — not a bespoke
		// prev/next, which is what made this screen look different. Rendered even on
		// a single page so the "Showing X-Y of N" summary always answers "how many?".
		$carry = array_filter(
			array(
				's'        => $search,
				'type'     => $type,
				'status'   => $status,
				'space_id' => $space_id ?: '',
				'from'     => $date_from,
				'to'       => $date_to,
			),
			static fn( $v ): bool => '' !== (string) $v
		);
		AdminPageBase::render_pagination(
			$paged,
			$pages,
			$total,
			self::PER_PAGE,
			static fn( int $p ): string => add_query_arg( array_merge( $carry, array( 'paged' => $p ) ), $base_url ),
			__( 'Activity pagination', 'buddynext' )
		);
	}

	/**
	 * Render the focused edit panel for one post.
	 *
	 * @param int $post_id Post being edited.
	 * @return void
	 */
	private function render_edit( int $post_id ): void {
		$posts = buddynext_service( 'post_service' );
		$post  = $posts->get( $post_id );
		$base  = AdminHub::tab_url( 'engagement', 'activity' );

		if ( ! is_array( $post ) ) {
			AdminPageBase::render_notice( __( 'That activity no longer exists.', 'buddynext' ), 'error' );
			echo '<p><a class="bn-btn" data-variant="secondary" href="' . esc_url( $base ) . '">' . esc_html__( 'Back to activity', 'buddynext' ) . '</a></p>';
			return;
		}

		$author = get_userdata( (int) $post['user_id'] );
		?>
		<div class="bn-settings-section bn-activity-edit">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Edit activity', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ss-body">
				<p class="bn-av-section-desc">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: author name, 2: post date. */
							__( 'Posted by %1$s on %2$s. Editing here re-runs the content safeguards and marks the post edited.', 'buddynext' ),
							$author ? $author->display_name : __( '(unknown)', 'buddynext' ),
							(string) $post['created_at']
						)
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="bn_activity_edit">
					<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
					<?php wp_nonce_field( 'bn_activity_edit_' . $post_id ); ?>
					<label for="bn-activity-content" class="screen-reader-text"><?php esc_html_e( 'Activity content', 'buddynext' ); ?></label>
					<textarea id="bn-activity-content" name="content" class="bn-textarea large-text" rows="8"><?php echo esc_textarea( (string) $post['content'] ); ?></textarea>
					<p class="bn-activity-edit__actions">
						<button type="submit" class="bn-btn" data-variant="primary"><?php esc_html_e( 'Save changes', 'buddynext' ); ?></button>
						<a class="bn-btn" data-variant="ghost" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></a>
						<a class="bn-btn" data-variant="ghost" href="<?php echo esc_url( PageRouter::post_url( $post_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View on site', 'buddynext' ); ?></a>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Delete a post as an admin moderation action.
	 *
	 * @return void
	 */
	public function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		check_admin_referer( 'bn_activity_delete_' . $post_id );

		$result = buddynext_service( 'post_service' )->delete( $post_id, get_current_user_id() );
		$this->redirect_back( is_wp_error( $result ) ? 'error' : 'deleted', is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	/**
	 * Save an admin edit of a post's content.
	 *
	 * @return void
	 */
	public function handle_edit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		check_admin_referer( 'bn_activity_edit_' . $post_id );

		$content = isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '';
		$result  = buddynext_service( 'post_service' )->update( $post_id, get_current_user_id(), array( 'content' => $content ) );
		$this->redirect_back( is_wp_error( $result ) ? 'error' : 'edited', is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	/**
	 * Redirect back to the list with a status flag.
	 *
	 * @param string $status Result slug (deleted|edited|error).
	 * @param string $msg    Optional error message.
	 * @return void
	 */
	private function redirect_back( string $status, string $msg = '' ): void {
		$args = array( 'bn_done' => $status );
		if ( '' !== $msg ) {
			$args['bn_msg'] = rawurlencode( $msg );
		}
		wp_safe_redirect( add_query_arg( $args, AdminHub::tab_url( 'engagement', 'activity' ) ) );
		exit;
	}

	/**
	 * Warm the user cache for every author on the page in one query.
	 *
	 * @param array<int,array<string,mixed>> $items Rows.
	 * @return void
	 */
	private function prime_authors( array $items ): void {
		$ids = array_values( array_unique( array_map( static fn( $r ) => (int) $r['user_id'], $items ) ) );
		if ( ! empty( $ids ) ) {
			cache_users( $ids );
		}
	}

	/**
	 * Resolve space names for the page's rows in one query.
	 *
	 * @param array<int,array<string,mixed>> $items Rows.
	 * @return array<int,string> space_id => name.
	 */
	private function space_names( array $items ): array {
		$ids = array_values( array_unique( array_filter( array_map( static fn( $r ) => (int) $r['space_id'], $items ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		global $wpdb;
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name FROM {$wpdb->prefix}bn_spaces WHERE id IN ({$in})", $ids ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['id'] ] = (string) $r['name'];
		}
		return $out;
	}

	/**
	 * A short, single-line content preview.
	 *
	 * @param string $content Raw content.
	 * @return string
	 */
	private function preview( string $content ): string {
		$text = trim( wp_strip_all_tags( $content ) );
		if ( '' === $text ) {
			return __( '(no text)', 'buddynext' );
		}
		return wp_trim_words( $text, 18, '…' );
	}

	/**
	 * Human label for a post type.
	 *
	 * @param string $type Type slug.
	 * @return string
	 */
	private function type_label( string $type ): string {
		$labels = array(
			'text'         => __( 'Post', 'buddynext' ),
			'photo'        => __( 'Photo', 'buddynext' ),
			'media'        => __( 'Media', 'buddynext' ),
			'file'         => __( 'File', 'buddynext' ),
			'link'         => __( 'Link', 'buddynext' ),
			'poll'         => __( 'Poll', 'buddynext' ),
			'share'        => __( 'Share', 'buddynext' ),
			'announcement' => __( 'Announcement', 'buddynext' ),
			'discussion'   => __( 'Discussion', 'buddynext' ),
			'event'        => __( 'Event', 'buddynext' ),
		);
		return $labels[ $type ] ?? ucwords( str_replace( array( '_', '-' ), ' ', $type ) );
	}

	/**
	 * Badge tone for a post type.
	 *
	 * @param string $type            Type slug.
	 * @param int    $is_announcement Whether the row is an announcement.
	 * @return string
	 */
	private function type_tone( string $type, int $is_announcement ): string {
		if ( $is_announcement > 0 || 'announcement' === $type ) {
			return 'warning';
		}
		return in_array( $type, array( 'photo', 'media', 'file', 'link' ), true ) ? 'info' : 'neutral';
	}

	/**
	 * Human label for a status.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	private function status_label( string $status ): string {
		$labels = array(
			'published' => __( 'Published', 'buddynext' ),
			'scheduled' => __( 'Scheduled', 'buddynext' ),
			'pending'   => __( 'Pending', 'buddynext' ),
			'draft'     => __( 'Draft', 'buddynext' ),
			'deleted'   => __( 'Deleted', 'buddynext' ),
		);
		return $labels[ $status ] ?? ucfirst( $status );
	}

	/**
	 * Badge tone for a status.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	private function status_tone( string $status ): string {
		$tones = array(
			'published' => 'success',
			'scheduled' => 'info',
			'pending'   => 'warning',
			'draft'     => 'neutral',
			'deleted'   => 'danger',
		);
		return $tones[ $status ] ?? 'neutral';
	}

	/**
	 * Relative time string from a UTC datetime.
	 *
	 * @param string $created_at UTC 'Y-m-d H:i:s'.
	 * @return string
	 */
	private function ago( string $created_at ): string {
		$ts = strtotime( $created_at . ' UTC' );
		if ( ! $ts ) {
			return $created_at;
		}
		return sprintf(
			/* translators: %s: human-readable time difference, e.g. "3 hours". */
			__( '%s ago', 'buddynext' ),
			human_time_diff( $ts, time() )
		);
	}
}

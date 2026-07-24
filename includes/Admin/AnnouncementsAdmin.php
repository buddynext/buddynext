<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Announcements management admin screen.
 *
 * Lists every announcement (site-wide + per-space, any status) so a site owner can
 * see what is live, feature one to the top of the home feed, end one early, or
 * delete it — without the REST API or SQL that retiring an announcement used to
 * require. Multiple announcements can be active at once; the featured one (or the
 * newest, if none is featured) leads the home feed.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Renders and handles the Engagement → Announcements tab.
 */
class AnnouncementsAdmin {

	/**
	 * Option holding the featured (pinned-to-top) announcement ID.
	 */
	private const FEATURED_OPTION = 'buddynext_featured_announcement';

	/**
	 * Register the tab + the admin-post action handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		AdminHub::register_tab(
			'growth',
			'announcements',
			__( 'Announcements', 'buddynext' ),
			array( $this, 'render_page' ),
			array(
				'cap'      => 'manage_options',
				'position' => 15,
				'subtitle' => __( 'See every announcement, feature one to the top of home, or end it early.', 'buddynext' ),
			)
		);

		add_action( 'admin_post_buddynext_announcement_action', array( $this, 'handle_action' ) );
	}

	/**
	 * Handle a Feature / Unfeature / End / Delete action.
	 *
	 * @return void
	 */
	public function handle_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ) );
		}
		check_admin_referer( 'buddynext_announcement_action' );

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$action  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$service = function_exists( 'buddynext_service' ) ? buddynext_service( 'feed' ) : null;

		if ( $post_id > 0 && is_object( $service ) ) {
			switch ( $action ) {
				case 'feature':
					update_option( self::FEATURED_OPTION, $post_id );
					break;
				case 'unfeature':
					if ( (int) get_option( self::FEATURED_OPTION, 0 ) === $post_id ) {
						delete_option( self::FEATURED_OPTION );
					}
					break;
				case 'end':
					$service->end_announcement_now( $post_id );
					break;
				case 'delete':
					buddynext_service( 'post_service' )->delete( $post_id, get_current_user_id() );
					if ( (int) get_option( self::FEATURED_OPTION, 0 ) === $post_id ) {
						delete_option( self::FEATURED_OPTION );
					}
					break;
			}
		}

		wp_safe_redirect( AdminHub::tab_url( 'engagement', 'announcements' ) );
		exit;
	}

	/**
	 * Render the announcements management table.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$service     = function_exists( 'buddynext_service' ) ? buddynext_service( 'feed' ) : null;
		$rows        = is_object( $service ) ? $service->list_all_announcements( 200 ) : array();
		$featured_id = (int) get_option( self::FEATURED_OPTION, 0 );
		$now         = time();
		?>
		<?php // S5: no card header — its title would only repeat the page H1 ("Announcements"). ?>
		<div class="bn-settings-section">
			<?php if ( empty( $rows ) ) : ?>
				<div class="bn-empty">
					<p class="bn-empty__title"><?php esc_html_e( 'No announcements yet', 'buddynext' ); ?></p>
					<p class="bn-empty__sub"><?php esc_html_e( 'Post one from the feed composer (choose the Announcement type) and it will appear here.', 'buddynext' ); ?></p>
				</div>
			<?php else : ?>
				<table class="widefat bn-announcements-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Announcement', 'buddynext' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Audience', 'buddynext' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'buddynext' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'buddynext' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $rows as $row ) :
							$id       = (int) ( $row['id'] ?? 0 );
							$space_id = (int) ( $row['space_id'] ?? 0 );
							$status   = $this->compute_status( $row, $now );
							$is_feat  = ( $id === $featured_id );
							$excerpt  = wp_trim_words( wp_strip_all_tags( (string) ( $row['content'] ?? '' ) ), 14, '…' );
							$is_site  = ( 0 === $space_id );
							?>
							<tr>
								<td>
									<?php echo esc_html( '' !== $excerpt ? $excerpt : __( '(no text)', 'buddynext' ) ); ?>
									<?php if ( $is_feat ) : ?>
										<span class="bn-badge" data-tone="accent"><?php esc_html_e( 'Featured', 'buddynext' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php echo $is_site ? esc_html__( 'Site-wide', 'buddynext' ) : esc_html( sprintf( /* translators: %d: space ID. */ __( 'Space #%d', 'buddynext' ), $space_id ) ); ?>
								</td>
								<td><span class="bn-badge" data-tone="<?php echo esc_attr( $status['tone'] ); ?>"><?php echo esc_html( $status['label'] ); ?></span></td>
								<td>
									<div class="bn-row-actions">
										<?php
										// Feature/unfeature only makes sense for a live site-wide announcement.
										if ( $is_site && 'active' === $status['key'] ) {
											$this->action_button( $id, $is_feat ? 'unfeature' : 'feature', $is_feat ? __( 'Unfeature', 'buddynext' ) : __( 'Feature', 'buddynext' ) );
										}
										if ( 'expired' !== $status['key'] ) {
											$this->action_button( $id, 'end', __( 'End now', 'buddynext' ) );
										}
										$this->action_button( $id, 'delete', __( 'Delete', 'buddynext' ), true );
										?>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Compute an announcement's display status.
	 *
	 * @param array<string,mixed> $row Announcement row.
	 * @param int                 $now Current unix time.
	 * @return array{key:string,label:string,tone:string}
	 */
	private function compute_status( array $row, int $now ): array {
		$status       = (string) ( $row['status'] ?? '' );
		$scheduled_at = (string) ( $row['scheduled_at'] ?? '' );
		$expires_at   = (string) ( $row['site_pin_expires_at'] ?? '' );

		if ( 'scheduled' === $status && '' !== $scheduled_at && strtotime( $scheduled_at . ' UTC' ) > $now ) {
			return array(
				'key'   => 'scheduled',
				'label' => __( 'Scheduled', 'buddynext' ),
				'tone'  => 'info',
			);
		}
		if ( '' !== $expires_at && strtotime( $expires_at . ' UTC' ) <= $now ) {
			return array(
				'key'   => 'expired',
				'label' => __( 'Ended', 'buddynext' ),
				'tone'  => 'muted',
			);
		}
		if ( 'published' !== $status ) {
			return array(
				'key'   => 'expired',
				'label' => __( 'Not live', 'buddynext' ),
				'tone'  => 'muted',
			);
		}
		return array(
			'key'   => 'active',
			'label' => __( 'Active', 'buddynext' ),
			'tone'  => 'success',
		);
	}

	/**
	 * Render one action as a nonce-protected admin-post form button.
	 *
	 * @param int    $post_id     Announcement post ID.
	 * @param string $action_key  Action key (feature|unfeature|end|delete).
	 * @param string $label       Button label.
	 * @param bool   $destructive Whether to style it as destructive + confirm.
	 * @return void
	 */
	private function action_button( int $post_id, string $action_key, string $label, bool $destructive = false ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-row-actions__form">
			<?php wp_nonce_field( 'buddynext_announcement_action' ); ?>
			<input type="hidden" name="action" value="buddynext_announcement_action">
			<input type="hidden" name="do" value="<?php echo esc_attr( $action_key ); ?>">
			<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
			<button type="submit"
				class="bn-btn"
				data-variant="<?php echo $destructive ? 'danger' : 'secondary'; ?>"
				data-size="sm"
				<?php if ( $destructive ) : ?>
				data-bn-confirm="<?php esc_attr_e( 'Delete this announcement permanently?', 'buddynext' ); ?>"
				data-bn-confirm-tone="danger"
				data-bn-confirm-ok="<?php esc_attr_e( 'Delete', 'buddynext' ); ?>"
				<?php endif; ?>
			><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}
}

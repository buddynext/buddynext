<?php
/**
 * BuddyNext template part: sidebar-your-spaces.
 *
 * Self-chromed "Your Spaces" / "Discover Spaces" sidebar card. Extracted
 * verbatim from the former `templates/partials/sidebar.php` so
 * FeedSidebarProvider can render it as a `chrome => false` widget
 * descriptor — markup and gating are unchanged, only the data now arrives
 * via the provider's render closure instead of the partial preparing it
 * inline. The provider only calls this part when the Spaces feature is
 * enabled (its descriptor `condition`), so this part no longer needs its
 * own `$bn_spaces_on` wrapper.
 *
 * @package BuddyNext
 *
 * @var array<int,object> $sbar_spaces     Rows from WidgetService::joined_spaces(). Empty renders the empty state.
 * @var string            $sbar_spaces_url PageRouter::spaces_url() — "Browse all spaces" link target and per-row URL base.
 * @var int               $sidebar_user_id Viewer ID (0 for guest) — selects "Your Spaces" vs "Discover Spaces" heading.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$sbar_spaces     = isset( $sbar_spaces ) ? (array) $sbar_spaces : array();
$sbar_spaces_url = isset( $sbar_spaces_url ) ? (string) $sbar_spaces_url : '';
$sidebar_user_id = isset( $sidebar_user_id ) ? (int) $sidebar_user_id : 0;
?>
<div class="bn-sidebar-card">
	<div class="bn-sidebar-card__header">
		<?php echo esc_html( $sidebar_user_id ? __( 'Your Spaces', 'buddynext' ) : __( 'Discover Spaces', 'buddynext' ) ); ?>
	</div>
	<div class="bn-sidebar-card__body">
		<?php if ( ! empty( $sbar_spaces ) ) : ?>
			<?php foreach ( $sbar_spaces as $sbar_sp ) : ?>
				<?php
				$sbar_sp_url      = \BuddyNext\Core\PageRouter::spaces_url() . rawurlencode( (string) $sbar_sp->slug ) . '/';
				$sbar_sp_initials = strtoupper( mb_substr( (string) $sbar_sp->name, 0, 2 ) );
				$sbar_sp_unread   = isset( $sbar_sp->unread_count ) ? (int) $sbar_sp->unread_count : 0;
				?>
				<a href="<?php echo esc_url( $sbar_sp_url ); ?>" class="bn-sbar-row bn-sbar-row--link">
					<span class="bn-sbar-row__icon" aria-hidden="true">
						<?php if ( ! empty( $sbar_sp->avatar_url ) ) : ?>
							<img src="<?php echo esc_url( $sbar_sp->avatar_url ); ?>" alt="" width="32" height="32" loading="lazy">
						<?php else : ?>
							<?php echo esc_html( $sbar_sp_initials ); ?>
						<?php endif; ?>
					</span>
					<span class="bn-sbar-row__info">
						<span class="bn-sbar-row__name"><?php echo esc_html( $sbar_sp->name ); ?></span>
						<span class="bn-sbar-row__meta">
							<?php
							$bn_sbar_mc = (int) $sbar_sp->member_count;
							/* translators: %s: formatted member count. */ printf( esc_html( _n( '%s member', '%s members', $bn_sbar_mc, 'buddynext' ) ), esc_html( number_format_i18n( $bn_sbar_mc ) ) );
							?>
							<?php // Count + "members" rendered together above via _n(). ?>
						</span>
					</span>
					<?php if ( $sbar_sp_unread > 0 ) : ?>
						<span class="bn-sbar-row__unread"
							aria-label="
							<?php
							/* translators: %d: unread space posts count */
							echo esc_attr( sprintf( _n( '%d unread post', '%d unread posts', $sbar_sp_unread, 'buddynext' ), $sbar_sp_unread ) );
							?>
							"></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
			<a href="<?php echo esc_url( $sbar_spaces_url ); ?>" class="bn-sidebar-see-all">
				<?php esc_html_e( 'Browse all spaces', 'buddynext' ); ?>
			</a>
		<?php else : ?>
			<p class="bn-sidebar-card__empty">
				<?php esc_html_e( 'Join your first space.', 'buddynext' ); ?>
			</p>
		<?php endif; ?>
	</div>
</div>

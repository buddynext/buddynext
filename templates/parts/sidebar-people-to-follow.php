<?php
/**
 * BuddyNext template part: sidebar-people-to-follow.
 *
 * Self-chromed "People to Follow" sidebar card. Extracted verbatim from the
 * former `templates/partials/sidebar.php` so FeedSidebarProvider can render
 * it as a `chrome => false` widget descriptor — markup and gating are
 * unchanged, only the data now arrives via the provider's render closure
 * instead of the partial preparing it inline.
 *
 * @package BuddyNext
 *
 * @var array<int,object> $sbar_suggested   Rows from WidgetService::suggested_follows(). Empty renders the empty state.
 * @var string            $sbar_members_url PageRouter::people_url() — "See all members" link target.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$sbar_suggested   = isset( $sbar_suggested ) ? (array) $sbar_suggested : array();
$sbar_members_url = isset( $sbar_members_url ) ? (string) $sbar_members_url : '';
?>
<div class="bn-sidebar-card">
	<div class="bn-sidebar-card__header">
		<?php esc_html_e( 'People to Follow', 'buddynext' ); ?>
	</div>
	<div class="bn-sidebar-card__body">
		<?php if ( ! empty( $sbar_suggested ) ) : ?>
			<?php foreach ( $sbar_suggested as $sbar_sug ) : ?>
				<?php
				$sbar_sug_id     = (int) ( $sbar_sug->ID ?? 0 );
				$sbar_sug_avatar = get_avatar_url( $sbar_sug_id, array( 'size' => 40 ) );
				$sbar_sug_url    = \BuddyNext\Core\PageRouter::profile_url( $sbar_sug_id );
				$sbar_sug_status = (string) ( $sbar_sug->follow_status ?? 'unfollowed' );
				?>
				<div class="bn-sbar-row">
					<a href="<?php echo esc_url( $sbar_sug_url ); ?>"
						class="bn-sbar-row__avatar"
						aria-label="<?php echo esc_attr( $sbar_sug->display_name ); ?>">
						<img src="<?php echo esc_url( $sbar_sug_avatar ); ?>"
							alt="<?php echo esc_attr( $sbar_sug->display_name ); ?>"
							width="36"
							height="36"
							loading="lazy">
					</a>
					<span class="bn-sbar-row__info">
						<a href="<?php echo esc_url( $sbar_sug_url ); ?>" class="bn-sbar-row__name">
							<?php echo esc_html( $sbar_sug->display_name ); ?>
						</a>
						<?php
						// Secondary line — the @handle (or "Request sent" when a
						// connection request is pending), matching the two-line member
						// row the "Online now" widget uses, so the card reads premium
						// rather than a bare name + button.
						$sbar_sug_handle = (string) ( $sbar_sug->user_nicename ?? ( $sbar_sug->user_login ?? '' ) );
						?>
						<?php if ( 'requested' === $sbar_sug_status ) : ?>
							<span class="bn-sbar-row__meta"><?php esc_html_e( 'Request sent', 'buddynext' ); ?></span>
						<?php elseif ( '' !== $sbar_sug_handle ) : ?>
							<span class="bn-sbar-row__meta">@<?php echo esc_html( $sbar_sug_handle ); ?></span>
						<?php endif; ?>
					</span>
					<?php
					$follow_user_id = $sbar_sug_id;
					buddynext_get_template(
						'partials/follow-button.php',
						array( 'user_id' => $follow_user_id )
					);
					?>
				</div>
			<?php endforeach; ?>
			<a href="<?php echo esc_url( $sbar_members_url ); ?>" class="bn-sidebar-see-all">
				<?php esc_html_e( 'See all members', 'buddynext' ); ?>
			</a>
		<?php else : ?>
			<p class="bn-sidebar-card__empty">
				<?php esc_html_e( "We'll suggest people once you've completed onboarding.", 'buddynext' ); ?>
			</p>
		<?php endif; ?>
	</div>
</div>

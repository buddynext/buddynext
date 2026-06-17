<?php
/**
 * Block template: Member Card (v2 design system).
 *
 * Compact card composition pulled from v2/member-directory.html — uses the
 * v2 attribute API (.bn-card[data-interactive], .bn-avatar[data-size],
 * .bn-btn[data-variant]).
 *
 * Variables:
 *   int $user_id WordPress user ID to display
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$user_id = $user_id ?? 0;

if ( ! $user_id ) {
	$user_id = get_current_user_id();
}

$user = $user_id ? get_userdata( $user_id ) : false;

if ( ! $user ) {
	return;
}

$viewer_id      = get_current_user_id();
$follower_count = buddynext_service( 'follows' )->follower_count( $user_id );

$avatar_url  = (string) get_avatar_url( $user_id, array( 'size' => 96 ) );
$profile_url = \BuddyNext\Core\PageRouter::profile_url( $user_id );
?>
<div class="bn-card bn-md-card bn-block-member-card" data-interactive data-user-id="<?php echo absint( $user_id ); ?>">

	<a href="<?php echo esc_url( $profile_url ); ?>" class="bn-md-card__avatar-link" tabindex="-1" aria-hidden="true">
		<span class="bn-avatar bn-md-card__avatar" data-size="xl">
			<?php if ( '' !== $avatar_url ) : ?>
				<img
					src="<?php echo esc_url( $avatar_url ); ?>"
					alt=""
					width="72"
					height="72"
					loading="lazy"
					decoding="async"
				>
			<?php endif; ?>
		</span>
	</a>

	<h3 class="bn-md-card__name">
		<a href="<?php echo esc_url( $profile_url ); ?>">
			<?php echo esc_html( (string) $user->display_name ); ?>
		</a>
	</h3>

	<p class="bn-md-card__meta">
		<?php
		printf(
			/* translators: %d: follower count */
			esc_html( _n( '%d follower', '%d followers', $follower_count, 'buddynext' ) ),
			absint( $follower_count )
		);
		?>
	</p>

	<?php if ( $viewer_id && $viewer_id !== $user_id ) : ?>
		<div class="bn-md-card__actions">
			<?php
			// Reuse the canonical Interactivity-API follow button — the only
			// correctly hydrated implementation — instead of a bespoke
			// data-action button no JS binds. Hydrated off-hub via the block's
			// @buddynext/social-buttons viewScriptModule.
			buddynext_get_template( 'blocks/follow-button.php', array( 'user_id' => $user_id ) );
			?>
		</div>
	<?php endif; ?>
</div>

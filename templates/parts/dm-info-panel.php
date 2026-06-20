<?php
/**
 * BuddyNext template part: dm-info-panel.
 *
 * The 1:1 conversation "info" panel — a modal over the thread that shows the
 * other person's identity, the media shared in the conversation, and the
 * conversation safety actions (View profile, Block, Report, Delete).
 *
 * Visibility is bound to `context.infoPanelOpen` (toggled from the thread
 * header's Info button). The shared-media grid is filled by the store on open
 * (collectSharedMedia → the `.bn-dm-bubble__media` thumbnails already loaded in
 * the thread); Block / Report reuse the canonical SocialGraph + moderation REST
 * paths. Recipient identity is SSR (the thread is a full page load per
 * conversation), so name/avatar/url are baked in here.
 *
 * Rendered for 1:1 threads only by templates/messages/native.php (groups use
 * dm-group-panel.php).
 *
 * @package BuddyNext
 *
 * @var string $display_name  Recipient display name.
 * @var int    $other_user_id Recipient user ID.
 * @var string $profile_url   Recipient profile URL.
 * @var string $avatar_html   Pre-rendered avatar <img>, or '' for a monogram.
 * @var string $initials      Monogram initials fallback.
 * @var string $tone          Monogram tone token.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_name     = isset( $display_name ) ? (string) $display_name : '';
$bn_url      = isset( $profile_url ) ? (string) $profile_url : '';
$bn_avatar   = isset( $avatar_html ) ? (string) $avatar_html : '';
$bn_initials = isset( $initials ) ? (string) $initials : '';
$bn_tone     = isset( $tone ) ? (string) $tone : '';
?>
<div
	class="bn-modal-backdrop bn-dm-info is-hidden"
	role="dialog"
	aria-modal="true"
	aria-labelledby="bn-dm-info-title"
	data-wp-class--is-hidden="!context.infoPanelOpen"
	data-wp-on--click="actions.closeInfoPanel"
>
	<div class="bn-modal__panel" data-size="sm" data-wp-on--click="actions.stopPropagation">
		<div class="bn-modal__head">
			<h2 id="bn-dm-info-title" class="bn-modal__title"><?php esc_html_e( 'Conversation info', 'buddynext' ); ?></h2>
			<button type="button" class="bn-modal__close" aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>" data-wp-on--click="actions.closeInfoPanel">
				<?php buddynext_icon( 'x' ); ?>
			</button>
		</div>

		<div class="bn-modal__body">
			<?php /* Person identity. */ ?>
			<div class="bn-dm-info__person">
				<span class="bn-dm-info__avatar">
					<?php
					if ( '' !== $bn_avatar ) {
						echo wp_kses_post( $bn_avatar );
					} else {
						printf(
							'<span class="bn-monogram" data-tone="%s" aria-hidden="true">%s</span>',
							esc_attr( $bn_tone ),
							esc_html( $bn_initials )
						);
					}
					?>
				</span>
				<span class="bn-dm-info__name"><?php echo esc_html( $bn_name ); ?></span>
				<?php if ( '' !== $bn_url ) : ?>
					<a class="bn-btn" data-variant="secondary" data-size="sm" href="<?php echo esc_url( $bn_url ); ?>">
						<?php esc_html_e( 'View profile', 'buddynext' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php /* Shared media — images sent in this conversation. Filled on open. */ ?>
			<div class="bn-dm-info__section">
				<h3 class="bn-dm-info__section-title"><?php esc_html_e( 'Shared photos', 'buddynext' ); ?></h3>
				<ul class="bn-dm-info__media" aria-label="<?php esc_attr_e( 'Shared photos', 'buddynext' ); ?>">
					<li class="bn-dm-info__media-empty"><?php esc_html_e( 'No photos shared yet.', 'buddynext' ); ?></li>
				</ul>
			</div>

			<?php /* Safety actions. */ ?>
			<div class="bn-dm-info__actions">
				<button type="button" class="bn-btn bn-dm-info__danger" data-variant="ghost" data-size="sm" data-wp-bind--disabled="context.infoBusy" data-wp-on--click="actions.reportRecipient">
					<?php buddynext_icon( 'flag' ); ?>
					<span><?php esc_html_e( 'Report conversation', 'buddynext' ); ?></span>
				</button>
				<button type="button" class="bn-btn bn-dm-info__danger" data-variant="ghost" data-size="sm" data-wp-bind--disabled="context.infoBusy" data-wp-on--click="actions.blockRecipient">
					<?php buddynext_icon( 'ban' ); ?>
					<span><?php esc_html_e( 'Block this member', 'buddynext' ); ?></span>
				</button>
				<button type="button" class="bn-btn bn-dm-info__danger" data-variant="ghost" data-size="sm" data-wp-on--click="actions.openDeleteConfirm">
					<?php buddynext_icon( 'trash' ); ?>
					<span><?php esc_html_e( 'Delete conversation', 'buddynext' ); ?></span>
				</button>
			</div>
		</div>
	</div>
</div>

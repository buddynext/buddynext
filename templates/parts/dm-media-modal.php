<?php
/**
 * BuddyNext template part: dm-media-modal.
 *
 * The "share a photo" picker for DM attachments (Messenger-style): pick from the
 * member's own WPMediaVerse media (reused by media_id — no duplicate upload) or
 * upload a new file (deduped by hash on the MVS side, stored as private media).
 *
 * Visibility is bound to context.mediaPickerOpen. The grid is fetched from
 * GET mvs/v1/media?author={me} and injected as DOM nodes by the store, so tile
 * clicks are delegated via actions.onMediaPick on the list.
 *
 * Used by: templates/messages/native.php.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div
	class="bn-modal-backdrop bn-dm-media is-hidden"
	role="dialog"
	aria-modal="true"
	aria-labelledby="bn-dm-media-title"
	data-wp-class--is-hidden="!context.mediaPickerOpen"
	data-wp-on--click="actions.closeMediaPicker"
>
	<div class="bn-modal__panel" data-size="md" data-wp-on--click="actions.stopPropagation">
		<div class="bn-modal__head">
			<h2 id="bn-dm-media-title" class="bn-modal__title"><?php esc_html_e( 'Share a photo', 'buddynext' ); ?></h2>
			<button type="button" class="bn-modal__close" aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>" data-wp-on--click="actions.closeMediaPicker">
				<?php buddynext_icon( 'x' ); ?>
			</button>
		</div>
		<div class="bn-modal__body">
			<button type="button" class="bn-btn bn-dm-media__upload" data-variant="secondary" data-size="md" data-wp-on--click="actions.openAttachment">
				<span class="bn-btn__icon" aria-hidden="true"><?php buddynext_icon( 'image' ); ?></span>
				<?php esc_html_e( 'Upload new', 'buddynext' ); ?>
			</button>
			<p class="bn-dm-media__label"><?php esc_html_e( 'Your photos', 'buddynext' ); ?></p>
			<ul class="bn-dm-media__grid" role="listbox" aria-label="<?php esc_attr_e( 'Your media', 'buddynext' ); ?>" data-wp-on--click="actions.onMediaPick">
				<li class="bn-dm-media__hint" data-bn-media-hint><?php esc_html_e( 'Loading your media…', 'buddynext' ); ?></li>
			</ul>
		</div>
	</div>
</div>

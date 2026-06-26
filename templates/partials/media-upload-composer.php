<?php
/**
 * Owner-only media upload composer (profile Media tab).
 *
 * A self-contained BuddyNext Interactivity island (`buddynext/media`). It posts
 * files to buddynext/v1/me/media and, on success, refreshes the sibling gallery
 * region ([data-bn-media-region]). No WPMediaVerse markup/CSS/JS is involved —
 * BuddyNext owns the entire experience.
 *
 * @var int $bn_mu_owner_id Profile owner id (always the current user — owner-only).
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$bn_mu_owner_id = isset( $bn_mu_owner_id ) ? (int) $bn_mu_owner_id : get_current_user_id();

$bn_mu_ctx = array(
	'restNonce' => wp_create_nonce( 'wp_rest' ),
	'ownerId'   => $bn_mu_owner_id,
	'maxFiles'  => 10,
	'maxSizeMB' => 64,
	'privacy'   => 'public',
	'staged'    => array(),
	'hasStaged' => false,
	'dragOver'  => false,
	'uploading' => false,
	'errorMsg'  => '',
	't'         => array(
		'badType'       => __( 'Only images, video and audio can be uploaded.', 'buddynext' ),
		'tooLarge'      => __( 'File is larger than the allowed size.', 'buddynext' ),
		/* translators: %d: maximum number of files allowed per upload batch. */
		'tooMany'       => __( 'You can upload up to %d files at once.', 'buddynext' ),
		'failed'        => __( 'Upload failed.', 'buddynext' ),
		/* translators: %d: number of files uploaded. */
		'uploaded'      => __( '%d uploaded.', 'buddynext' ),
		/* translators: %d: number of files uploaded and posted to the feed. */
		'shared'        => __( '%d uploaded and shared to your feed.', 'buddynext' ),
		/* translators: %d: number of files that were already in the library (duplicates). */
		'dup'           => __( '%d already in your library.', 'buddynext' ),
		'someFailed'    => __( 'Some files could not be uploaded.', 'buddynext' ),
		'empty'         => __( 'No media uploaded yet.', 'buddynext' ),
		'remove'        => __( 'Remove', 'buddynext' ),
		'confirmDelete' => __( 'Remove this media? This cannot be undone.', 'buddynext' ),
		'removed'       => __( 'Media removed.', 'buddynext' ),
	),
);
?>
<div class="bn-card bn-mu"
	data-wp-interactive="buddynext/media"
	<?php
	echo wp_interactivity_data_wp_context( $bn_mu_ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns an escaped attribute.
	?>
	data-wp-init="callbacks.initComposer">

	<div class="bn-mu__dropzone"
		role="button"
		tabindex="0"
		aria-label="<?php esc_attr_e( 'Add photos, video or audio', 'buddynext' ); ?>"
		data-wp-class--bn-mu__dropzone--drag="context.dragOver"
		data-wp-on--click="actions.openPicker"
		data-wp-on--keydown="actions.openPicker"
		data-wp-on--dragover="actions.onDragOver"
		data-wp-on--dragleave="actions.onDragLeave"
		data-wp-on--drop="actions.onDrop">
		<span class="bn-mu__dropzone-icon" aria-hidden="true"><?php buddynext_icon( 'upload' ); ?></span>
		<span class="bn-mu__dropzone-title"><?php esc_html_e( 'Drag files here or click to upload', 'buddynext' ); ?></span>
		<span class="bn-mu__dropzone-hint"><?php esc_html_e( 'Photos, video or audio · up to 10 at once', 'buddynext' ); ?></span>
		<input class="bn-mu-input" type="file" accept="image/*,video/*,audio/*" multiple
			data-wp-on--change="actions.onFileSelect" hidden />
	</div>

	<p class="bn-mu__error" role="alert"
		data-wp-text="context.errorMsg"
		data-wp-bind--hidden="!context.errorMsg"></p>

	<div class="bn-mu__staged" data-wp-bind--hidden="!context.hasStaged">
		<div class="bn-mu__staged-grid">
			<template data-wp-each="context.staged" data-wp-each-key="context.item.name">
				<div class="bn-mu-file" data-wp-bind--data-index="context.item.idx"
					data-wp-class--bn-mu-file--error="context.item.isError">
					<img class="bn-mu-file__thumb" alt="" decoding="async"
						data-wp-bind--hidden="!context.item.isImage"
						data-wp-bind--src="context.item.preview" />
					<span class="bn-mu-file__skeleton" aria-hidden="true"
						data-wp-bind--hidden="!context.item.thumbLoading"></span>
					<span class="bn-mu-file__placeholder" aria-hidden="true"
						data-wp-bind--hidden="context.item.isImageKind"
						data-wp-text="context.item.kind"></span>

					<span class="bn-mu-file__status bn-mu-file__status--uploading"
						data-wp-bind--hidden="!context.item.isUploading"></span>
					<span class="bn-mu-file__status bn-mu-file__status--done"
						data-wp-bind--hidden="!context.item.isDone">&#10003;</span>

					<button type="button" class="bn-mu-file__remove"
						aria-label="<?php esc_attr_e( 'Remove file', 'buddynext' ); ?>"
						data-wp-bind--hidden="context.item.isUploading"
						data-wp-on--click="actions.removeStaged">&times;</button>

					<span class="bn-mu-file__error"
						data-wp-bind--hidden="!context.item.isError"
						data-wp-text="context.item.error"></span>
				</div>
			</template>
		</div>

		<div class="bn-mu__bar">
			<label class="bn-mu__privacy">
				<span class="bn-mu__privacy-label"><?php esc_html_e( 'Who can see this', 'buddynext' ); ?></span>
				<select class="bn-input bn-mu__privacy-select" data-wp-on--change="actions.setPrivacy">
					<option value="public"><?php esc_html_e( 'Public', 'buddynext' ); ?></option>
					<option value="followers"><?php esc_html_e( 'Followers', 'buddynext' ); ?></option>
					<option value="connections"><?php esc_html_e( 'Connections', 'buddynext' ); ?></option>
					<option value="private"><?php esc_html_e( 'Only me', 'buddynext' ); ?></option>
				</select>
			</label>
			<div class="bn-mu__actions">
				<button type="button" class="bn-btn" data-variant="ghost"
					data-wp-on--click="actions.clearStaged"
					data-wp-bind--hidden="context.uploading">
					<?php esc_html_e( 'Clear', 'buddynext' ); ?>
				</button>
				<button type="button" class="bn-btn" data-variant="primary"
					data-wp-on--click="actions.startUpload"
					data-wp-bind--disabled="!state.canUpload">
					<span data-wp-bind--hidden="context.uploading"><?php esc_html_e( 'Upload', 'buddynext' ); ?></span>
					<span data-wp-bind--hidden="!context.uploading"><?php esc_html_e( 'Uploading…', 'buddynext' ); ?></span>
				</button>
			</div>
		</div>
	</div>
</div>

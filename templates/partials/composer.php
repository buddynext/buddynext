<?php
/**
 * BuddyNext — Post composer partial.
 *
 * Shared composer form used on home feed and space feeds.
 * When $space_id is provided, posts are created in that space.
 * When $space_id is null, posts go to the user's general feed.
 *
 * Variables:
 *   int|null $space_id        Target space ID (null = general feed).
 *   int      $current_user_id Current user ID.
 *
 * Overridable: copy to {theme}/buddynext/partials/composer.php
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$composer_user_id = absint( $current_user_id ?? 0 );
$composer_space   = isset( $space_id ) ? absint( $space_id ) : null;

if ( 0 === $composer_user_id ) {
	return;
}

$composer_user = get_userdata( $composer_user_id );
if ( ! $composer_user ) {
	return;
}

$composer_display  = $composer_user->display_name;
$composer_avatar   = get_avatar_url( $composer_user_id, array( 'size' => 76 ) );
$composer_initial  = mb_substr( $composer_display, 0, 1 );
$composer_nonce    = wp_create_nonce( 'wp_rest' );
$composer_color    = function_exists( 'bn_avatar_color' ) ? bn_avatar_color( $composer_user_id ) : buddynext_avatar_colour( $composer_user_id );

$composer_placeholder = $composer_space
	? sprintf(
		/* translators: %s: space name */
		__( 'Share something with this space...', 'buddynext' ),
	)
	: __( "What's on your mind?", 'buddynext' );
?>
<div class="bn-composer"
	data-wp-interactive="buddynext/post-composer"
	data-wp-context='
	<?php
	echo esc_attr(
		wp_json_encode(
			array(
				'restUrl'        => rest_url( 'buddynext/v1' ),
				'mvsRestBase'    => rest_url( 'mvs/v1' ),
				'restNonce'      => $composer_nonce,
				'spaceId'        => $composer_space,
				'composerOpen'   => false,
				'composerType'   => 'text',
				'privacy'        => $composer_space ? 'space_members' : 'public',
				'content'        => '',
				'submitting'     => false,
				'mediaIds'       => array(),
				'mediaPreviews'  => array(),
				'mediaUploading' => false,
			)
		)
	);
	?>
	'>
	<!-- Collapsed trigger (hidden when open) -->
	<div data-wp-bind--hidden="state.open">
		<div class="bn-composer__top">
			<div class="bn-composer__avatar" aria-hidden="true"
				<?php if ( ! $composer_avatar ) : ?>
					style="background:<?php echo esc_attr( $composer_color ); ?>;"
				<?php endif; ?>
			>
				<?php if ( $composer_avatar ) : ?>
					<img src="<?php echo esc_url( $composer_avatar ); ?>" alt="" width="38" height="38">
				<?php else : ?>
					<?php echo esc_html( $composer_initial ); ?>
				<?php endif; ?>
			</div>
			<div class="bn-composer__input"
				role="button"
				tabindex="0"
				data-wp-on--click="actions.open"
				data-wp-on--keydown="actions.openOnEnter">
				<?php echo esc_html( $composer_placeholder ); ?>
			</div>
		</div>
		<div class="bn-composer__actions">
			<button class="bn-composer__action-btn"
					data-wp-on--click="actions.openPhoto"
					type="button">
				<span class="bn-composer__action-icon" aria-hidden="true"><?php buddynext_icon( 'image' ); ?></span>
				<?php esc_html_e( 'Photo', 'buddynext' ); ?>
			</button>
			<button class="bn-composer__action-btn"
					data-wp-on--click="actions.openPoll"
					type="button">
				<span class="bn-composer__action-icon" aria-hidden="true"><?php buddynext_icon( 'bar-chart' ); ?></span>
				<?php esc_html_e( 'Poll', 'buddynext' ); ?>
			</button>
			<button class="bn-composer__action-btn"
					data-wp-on--click="actions.openLink"
					type="button">
				<span class="bn-composer__action-icon" aria-hidden="true"><?php buddynext_icon( 'link' ); ?></span>
				<?php esc_html_e( 'Link', 'buddynext' ); ?>
			</button>
		</div>
	</div>
	<!-- Expanded composer form (shown when open) -->
	<div class="bn-composer__expanded"
		hidden
		data-wp-bind--hidden="!state.open">
		<div class="bn-composer__body">
			<div class="bn-composer__avatar" aria-hidden="true"
				<?php if ( ! $composer_avatar ) : ?>
					style="background:<?php echo esc_attr( $composer_color ); ?>;"
				<?php endif; ?>
			>
				<?php if ( $composer_avatar ) : ?>
					<img src="<?php echo esc_url( $composer_avatar ); ?>" alt="" width="38" height="38">
				<?php else : ?>
					<?php echo esc_html( $composer_initial ); ?>
				<?php endif; ?>
			</div>
			<div style="flex:1;display:flex;flex-direction:column;gap:var(--s2);">
				<textarea
					class="bn-composer__textarea"
					placeholder="<?php echo esc_attr( $composer_placeholder ); ?>"
					data-wp-on--input="actions.onInput"
					rows="4"
					aria-label="<?php esc_attr_e( 'Post content', 'buddynext' ); ?>"></textarea>
				<!-- Hidden file input for media uploads -->
				<input
					type="file"
					class="bn-composer__file-input"
					accept="image/*,video/*"
					multiple
					hidden
					data-wp-on--change="actions.handleMediaUpload"
					aria-label="<?php esc_attr_e( 'Upload media', 'buddynext' ); ?>">
				<!-- Media preview thumbnails -->
				<div class="bn-composer__media-preview"
					hidden
					data-wp-bind--hidden="!state.hasMedia">
					<template data-wp-each="state.mediaPreviews">
						<div class="bn-composer__media-thumb">
							<img data-wp-bind--src="context.item.url" alt="" width="80" height="80" loading="lazy">
							<button
								class="bn-composer__media-remove"
								type="button"
								data-wp-on--click="actions.removeMedia"
								data-wp-bind--data-media-id="context.item.id"
								aria-label="<?php esc_attr_e( 'Remove', 'buddynext' ); ?>">&times;</button>
						</div>
					</template>
				</div>
				<!-- Poll options — shown only in poll mode -->
				<div class="bn-composer__poll-options"
					hidden
					data-wp-bind--hidden="state.isNotPoll">
					<p class="bn-composer__poll-question"><?php esc_html_e( 'Poll options (min 2)', 'buddynext' ); ?></p>
					<input type="text" class="bn-composer__poll-option"
						placeholder="<?php esc_attr_e( 'Option 1', 'buddynext' ); ?>"
						aria-label="<?php esc_attr_e( 'Poll option 1', 'buddynext' ); ?>">
					<input type="text" class="bn-composer__poll-option"
						placeholder="<?php esc_attr_e( 'Option 2', 'buddynext' ); ?>"
						aria-label="<?php esc_attr_e( 'Poll option 2', 'buddynext' ); ?>">
					<input type="text" class="bn-composer__poll-option"
						placeholder="<?php esc_attr_e( 'Option 3 (optional)', 'buddynext' ); ?>"
						aria-label="<?php esc_attr_e( 'Poll option 3', 'buddynext' ); ?>">
					<input type="text" class="bn-composer__poll-option"
						placeholder="<?php esc_attr_e( 'Option 4 (optional)', 'buddynext' ); ?>"
						aria-label="<?php esc_attr_e( 'Poll option 4', 'buddynext' ); ?>">
				</div>
			</div>
		</div>
		<div class="bn-composer__footer">
			<div class="bn-composer__footer-actions">
				<button class="bn-composer__footer-btn" type="button" data-wp-on--click="actions.pickMedia" title="<?php esc_attr_e( 'Photo / Video', 'buddynext' ); ?>">
					<?php buddynext_icon( 'image' ); ?>
				</button>
				<button class="bn-composer__footer-btn" type="button" data-wp-on--click="actions.openPoll" title="<?php esc_attr_e( 'Poll', 'buddynext' ); ?>">
					<?php buddynext_icon( 'bar-chart' ); ?>
				</button>
				<button class="bn-composer__footer-btn" type="button" data-wp-on--click="actions.openLink" title="<?php esc_attr_e( 'Link', 'buddynext' ); ?>">
					<?php buddynext_icon( 'link' ); ?>
				</button>
			</div>
			<div class="bn-composer__footer-right">
				<?php if ( ! $composer_space ) : ?>
				<select
					class="bn-composer__select"
					data-wp-on--change="actions.setPrivacy"
					aria-label="<?php esc_attr_e( 'Post privacy', 'buddynext' ); ?>">
					<option value="public"><?php esc_html_e( 'Public', 'buddynext' ); ?></option>
					<option value="followers"><?php esc_html_e( 'Followers', 'buddynext' ); ?></option>
					<option value="private"><?php esc_html_e( 'Only me', 'buddynext' ); ?></option>
				</select>
				<?php endif; ?>
				<button
					class="bn-composer__cancel"
					type="button"
					data-wp-on--click="actions.cancel">
					<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
				</button>
				<button
					class="bn-composer__submit"
					type="button"
					data-wp-on--click="actions.submit"
					data-wp-bind--disabled="state.submitting">
					<?php esc_html_e( 'Post', 'buddynext' ); ?>
				</button>
			</div>
		</div>
	</div>
</div>

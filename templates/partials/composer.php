<?php
/**
 * BuddyNext — Post composer partial (v2 single-state).
 *
 * Always-visible composer matching the docs/v2 Plans/v2/home-feed.html
 * `.composer` block. Avatar + textarea + tools row (image / poll / link
 * icons + privacy select + Share button) rendered as one block — no
 * collapsed-pill trigger. State (poll mode, media previews, schedule,
 * submission) is driven by the WP Interactivity API store
 * `buddynext/post-composer` (see assets/js/feed/store.js).
 *
 * Variables:
 *   int|null $space_id        Target space ID (null = general feed).
 *   int      $current_user_id Current user ID.
 *
 * Overridable: copy to {theme}/buddynext/partials/composer.php.
 *
 * Fires:
 *   - do_action( 'buddynext_part_composer_before', $args )
 *   - do_action( 'buddynext_part_composer_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_composer_args', $args )
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

$composer_display = $composer_user->display_name;
$composer_avatar  = get_avatar_url( $composer_user_id, array( 'size' => 76 ) );
$composer_initial = mb_substr( $composer_display, 0, 1 );
$composer_nonce   = wp_create_nonce( 'wp_rest' );
$composer_color   = function_exists( 'bn_avatar_color' )
	? bn_avatar_color( $composer_user_id )
	: ( function_exists( 'buddynext_avatar_colour' ) ? buddynext_avatar_colour( $composer_user_id ) : '#cccccc' );

$composer_placeholder = $composer_space
	? __( 'Share something with this space...', 'buddynext' )
	: sprintf(
		/* translators: %s: viewer display name */
		__( "What's on your mind, %s?", 'buddynext' ),
		$composer_display
	);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters(
	'buddynext_part_composer_args',
	array(
		'user_id'     => $composer_user_id,
		'space_id'    => $composer_space,
		'placeholder' => $composer_placeholder,
		'display'     => $composer_display,
		'avatar_url'  => $composer_avatar,
		'avatar_init' => $composer_initial,
	)
);

do_action( 'buddynext_part_composer_before', $args );
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
				'composerOpen'   => true,
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

	<span class="bn-avatar bn-composer__avatar"
		data-size="md"
		<?php if ( ! $composer_avatar ) : ?>
			style="background:<?php echo esc_attr( $composer_color ); ?>;"
		<?php endif; ?>
		aria-hidden="true">
		<?php if ( $composer_avatar ) : ?>
			<img src="<?php echo esc_url( $composer_avatar ); ?>"
				alt=""
				width="36"
				height="36"
				loading="lazy">
		<?php else : ?>
			<?php echo esc_html( (string) $composer_initial ); ?>
		<?php endif; ?>
	</span>

	<div class="bn-composer__input">

		<textarea class="bn-composer__prompt"
			rows="2"
			placeholder="<?php echo esc_attr( (string) $args['placeholder'] ); ?>"
			data-wp-on--input="actions.onInput"
			aria-label="<?php esc_attr_e( 'Post content', 'buddynext' ); ?>"></textarea>

		<input
			type="file"
			class="bn-composer__file-input"
			accept="image/*,video/*"
			multiple
			hidden
			aria-label="<?php esc_attr_e( 'Upload media', 'buddynext' ); ?>">

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

		<div class="bn-composer__tools">

			<button class="bn-composer__tool"
				type="button"
				data-wp-on--click="actions.pickMedia"
				aria-label="<?php esc_attr_e( 'Image', 'buddynext' ); ?>">
				<?php buddynext_icon( 'image' ); ?>
			</button>

			<button class="bn-composer__tool"
				type="button"
				data-wp-on--click="actions.openPoll"
				aria-label="<?php esc_attr_e( 'Poll', 'buddynext' ); ?>">
				<?php buddynext_icon( 'bar-chart' ); ?>
			</button>

			<button class="bn-composer__tool"
				type="button"
				data-wp-on--click="actions.openLink"
				aria-label="<?php esc_attr_e( 'Link', 'buddynext' ); ?>">
				<?php buddynext_icon( 'link' ); ?>
			</button>

			<span class="bn-composer__spacer"></span>

			<?php if ( ! $composer_space ) : ?>
				<select
					class="bn-composer__privacy"
					data-wp-on--change="actions.setPrivacy"
					aria-label="<?php esc_attr_e( 'Post privacy', 'buddynext' ); ?>">
					<option value="public"><?php esc_html_e( 'Public', 'buddynext' ); ?></option>
					<option value="followers"><?php esc_html_e( 'Followers', 'buddynext' ); ?></option>
					<option value="private"><?php esc_html_e( 'Only me', 'buddynext' ); ?></option>
				</select>
			<?php endif; ?>

			<button
				class="bn-btn bn-composer__submit"
				type="button"
				data-variant="primary"
				data-size="sm"
				data-wp-on--click="actions.submit"
				data-wp-bind--disabled="state.submitting">
				<?php esc_html_e( 'Share', 'buddynext' ); ?>
			</button>

		</div>

	</div>

</div>
<?php
do_action( 'buddynext_part_composer_after', $args );

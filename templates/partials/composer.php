<?php
/**
 * BuddyNext — Post composer partial (v2 single-state).
 *
 * Always-visible composer matching the docs/v2 Plans/v2/home-feed.html
 * `.composer` block. Avatar + textarea + tools row + chip-style privacy
 * selector + Share button rendered as one block — no collapsed-pill
 * trigger. Five tool affordances: Image, Poll, Event, Voice, AI helper.
 * State (composer mode, media previews, privacy, submission, errors) is
 * driven by the WP Interactivity API store `buddynext/post-composer`
 * (see assets/js/feed/store.js).
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

$composer_has_pro = defined( 'BUDDYNEXTPRO_VERSION' );

// Media uploads route through WPMediaVerse. When the engine is absent the Image
// affordance must not render — otherwise pickMedia() POSTs to a non-existent
// mvs/v1 route and 404s. BN degrades gracefully: no button, no console error.
$composer_media_enabled = class_exists( '\BuddyNext\Media\MediaClient' )
	&& \BuddyNext\Media\MediaClient::available();

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
		'has_pro'     => $composer_has_pro,
	)
);

do_action( 'buddynext_part_composer_before', $args );

$default_privacy = $composer_space ? 'space_members' : (string) get_option( 'buddynext_default_post_privacy', 'public' );
?>
<div class="bn-composer"
	data-wp-interactive="buddynext/post-composer"
	data-wp-init="callbacks.restoreDraft"
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
				'privacy'        => $default_privacy,
				'privacyOpen'    => false,
				'content'        => '',
				'submitting'     => false,
				'mediaIds'       => array(),
				'mediaPreviews'  => array(),
				'mediaUploading' => false,
				'errorMessage'   => '',
				'eventOpen'      => false,
				'scheduleOpen'   => false,
				'scheduledAt'    => '',
				'hasPro'         => $composer_has_pro,
				'userId'         => get_current_user_id(),
				'isAdmin'        => current_user_can( 'manage_options' ),
				'announcementExpiresAt' => '',
				'draftStatus'    => '',
				'hasDraft'       => false,
				'linkPreviewEnabled' => (bool) get_option( 'buddynext_enable_link_preview', true ),
				'linkUrl'        => '',
				'linkTitle'      => '',
				'linkDesc'       => '',
				'linkThumb'      => '',
				'linkMeta'       => null,
				'mediaEnabled'   => $composer_media_enabled,
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

		<div class="bn-composer__error"
			role="alert"
			hidden
			data-wp-bind--hidden="state.hasNoError">
			<span class="bn-composer__error-text" data-wp-text="state.errorMessage"></span>
			<button class="bn-composer__error-retry"
				type="button"
				data-wp-on--click="actions.submit"><?php esc_html_e( 'Retry', 'buddynext' ); ?></button>
		</div>

		<textarea class="bn-composer__prompt"
			rows="2"
			placeholder="<?php echo esc_attr( (string) $args['placeholder'] ); ?>"
			data-wp-on--input="actions.onInput"
			data-wp-bind--disabled="state.submitting"
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

		<?php if ( (bool) get_option( 'buddynext_enable_link_preview', true ) ) : ?>
		<div class="bn-composer__link-preview"
			hidden
			data-wp-bind--hidden="!state.hasLinkPreview">
			<a class="bn-composer__link-card"
				data-wp-bind--href="context.linkUrl"
				target="_blank"
				rel="noopener noreferrer">
				<span class="bn-composer__link-thumb"
					hidden
					data-wp-bind--hidden="!state.hasLinkThumb">
					<img data-wp-bind--src="context.linkThumb" alt="" loading="lazy">
				</span>
				<span class="bn-composer__link-info">
					<strong class="bn-composer__link-title" data-wp-text="context.linkTitle"></strong>
					<small class="bn-composer__link-desc" data-wp-text="context.linkDesc"></small>
					<span class="bn-composer__link-domain" data-wp-text="state.linkDomain"></span>
				</span>
			</a>
			<button type="button"
				class="bn-composer__link-remove"
				data-wp-on--click="actions.removeLinkPreview"
				aria-label="<?php esc_attr_e( 'Remove link preview', 'buddynext' ); ?>"
				title="<?php esc_attr_e( 'Remove link preview', 'buddynext' ); ?>">
				<?php buddynext_icon( 'x' ); ?>
			</button>
		</div>
		<?php endif; ?>

		<div class="bn-composer__poll-options"
			hidden
			data-wp-bind--hidden="state.isNotPoll">
			<div class="bn-composer__poll-head">
				<p class="bn-composer__poll-question"><?php esc_html_e( 'Poll options (min 2)', 'buddynext' ); ?></p>
				<button type="button"
					class="bn-composer__poll-close"
					data-wp-on--click="actions.togglePoll"
					aria-label="<?php esc_attr_e( 'Cancel poll', 'buddynext' ); ?>"
					title="<?php esc_attr_e( 'Cancel poll', 'buddynext' ); ?>">
					<?php buddynext_icon( 'x' ); ?>
				</button>
			</div>
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

		<div class="bn-composer__schedule"
			hidden
			data-wp-bind--hidden="state.isNotScheduled">
			<label class="bn-composer__schedule-label" for="bn-composer-schedule-at">
				<?php buddynext_icon( 'clock' ); ?>
				<span><?php esc_html_e( 'Publish at', 'buddynext' ); ?></span>
			</label>
			<input
				type="datetime-local"
				id="bn-composer-schedule-at"
				class="bn-composer__schedule-input"
				data-wp-on--input="actions.setScheduledAt"
				aria-label="<?php esc_attr_e( 'Publish date and time', 'buddynext' ); ?>">
			<button
				type="button"
				class="bn-composer__schedule-clear"
				data-wp-on--click="actions.toggleSchedule"
				aria-label="<?php esc_attr_e( 'Cancel scheduling', 'buddynext' ); ?>"
				title="<?php esc_attr_e( 'Cancel scheduling', 'buddynext' ); ?>">
				<?php buddynext_icon( 'x' ); ?>
			</button>
		</div>

		<?php if ( current_user_can( 'manage_options' ) ) : ?>
		<div class="bn-composer__schedule"
			hidden
			data-wp-bind--hidden="state.isNotAnnouncement">
			<label class="bn-composer__schedule-label" for="bn-composer-announce-expiry">
				<?php buddynext_icon( 'megaphone' ); ?>
				<span><?php esc_html_e( 'Announcement — auto-expire at (optional)', 'buddynext' ); ?></span>
			</label>
			<input
				type="datetime-local"
				id="bn-composer-announce-expiry"
				class="bn-composer__schedule-input"
				data-wp-on--input="actions.setAnnouncementExpiry"
				aria-label="<?php esc_attr_e( 'Announcement expiry date and time', 'buddynext' ); ?>">
			<button
				type="button"
				class="bn-composer__schedule-clear"
				data-wp-on--click="actions.toggleAnnouncement"
				aria-label="<?php esc_attr_e( 'Cancel announcement', 'buddynext' ); ?>"
				title="<?php esc_attr_e( 'Cancel announcement', 'buddynext' ); ?>">
				<?php buddynext_icon( 'x' ); ?>
			</button>
		</div>
		<?php endif; ?>

		<div class="bn-composer__tools">

			<?php if ( $composer_media_enabled ) : ?>
			<button class="bn-composer__tool"
				type="button"
				data-wp-on--click="actions.pickMedia"
				aria-label="<?php esc_attr_e( 'Image', 'buddynext' ); ?>"
				title="<?php esc_attr_e( 'Image', 'buddynext' ); ?>">
				<?php buddynext_icon( 'image' ); ?>
			</button>
			<?php endif; ?>

			<?php if ( (bool) get_option( 'buddynext_allow_polls', true ) ) : ?>
			<button class="bn-composer__tool"
				type="button"
				data-wp-bind--aria-pressed="state.isPoll"
				data-wp-on--click="actions.togglePoll"
				aria-label="<?php esc_attr_e( 'Poll', 'buddynext' ); ?>"
				title="<?php esc_attr_e( 'Poll', 'buddynext' ); ?>">
				<?php buddynext_icon( 'bar-chart-2' ); ?>
			</button>
			<?php endif; ?>

			<?php if ( (bool) get_option( 'buddynext_enable_emoji_picker', true ) ) : ?>
			<button class="bn-composer__tool bn-emoji-trigger"
				type="button"
				data-bn-emoji-target=".bn-composer__prompt"
				aria-label="<?php esc_attr_e( 'Insert emoji', 'buddynext' ); ?>"
				aria-haspopup="true"
				aria-expanded="false"
				title="<?php esc_attr_e( 'Insert emoji', 'buddynext' ); ?>">
				<?php buddynext_icon( 'smile' ); ?>
			</button>
			<?php endif; ?>

			<button class="bn-composer__tool"
				type="button"
				data-wp-on--click="actions.openEvent"
				aria-label="<?php esc_attr_e( 'Pin a date and location', 'buddynext' ); ?>"
				title="<?php esc_attr_e( 'Pin a date and location', 'buddynext' ); ?>">
				<?php buddynext_icon( 'calendar' ); ?>
			</button>

			<button class="bn-composer__tool"
				type="button"
				data-wp-bind--aria-pressed="state.isScheduled"
				data-wp-on--click="actions.toggleSchedule"
				aria-label="<?php esc_attr_e( 'Schedule for later', 'buddynext' ); ?>"
				title="<?php esc_attr_e( 'Schedule for later', 'buddynext' ); ?>">
				<?php buddynext_icon( 'clock' ); ?>
			</button>

			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<button class="bn-composer__tool"
					type="button"
					data-wp-bind--aria-pressed="state.isAnnouncement"
					data-wp-on--click="actions.toggleAnnouncement"
					aria-label="<?php esc_attr_e( 'Post as announcement', 'buddynext' ); ?>"
					title="<?php esc_attr_e( 'Post as announcement (pinned to everyone\'s feed)', 'buddynext' ); ?>">
					<?php buddynext_icon( 'megaphone' ); ?>
				</button>
			<?php endif; ?>

			<?php
			/**
			 * Composer tool injection point. Pro / 3rd-party plugins register
			 * extra composer tools (AI helper, voice room, file picker, etc.)
			 * by returning rendered button HTML.
			 *
			 * @param string $html    Concatenated tool-button HTML (empty by default).
			 * @param array  $context array{ user_id:int, space_id:?int, has_pro:bool }.
			 */
			echo (string) apply_filters(
				'buddynext_composer_tools',
				'',
				array(
					'user_id'  => $args['user_id'],
					'space_id' => $args['space_id'],
					'has_pro'  => $args['has_pro'],
				)
			);
			?>

			<span class="bn-composer__char-counter-slot" aria-live="polite"></span>

			<span class="bn-composer__spacer"></span>

			<?php if ( ! $composer_space ) : ?>
				<div class="bn-composer__privacy-wrap">
					<button
						type="button"
						class="bn-composer__privacy-chip"
						aria-haspopup="listbox"
						aria-expanded="false"
						data-wp-bind--aria-expanded="state.privacyOpen"
						data-wp-on--click="actions.togglePrivacy">
						<?php esc_html_e( 'Posting to', 'buddynext' ); ?>
						<strong data-wp-text="state.privacyLabel"><?php esc_html_e( 'Everyone', 'buddynext' ); ?></strong>
						<span class="bn-composer__privacy-caret" aria-hidden="true">
							<?php buddynext_icon( 'chevron-down' ); ?>
						</span>
					</button>
					<ul
						class="bn-composer__privacy-pop"
						role="listbox"
						hidden
						data-wp-bind--hidden="!state.privacyOpen">
						<li role="option" data-wp-bind--aria-selected="state.isPrivacyPublic">
							<button type="button"
								class="bn-composer__privacy-opt"
								data-privacy="public"
								data-wp-on--click="actions.setPrivacy">
								<?php buddynext_icon( 'globe' ); ?>
								<span class="bn-composer__privacy-opt-label">
									<strong><?php esc_html_e( 'Public', 'buddynext' ); ?></strong>
									<small><?php esc_html_e( 'Visible to everyone.', 'buddynext' ); ?></small>
								</span>
							</button>
						</li>
						<li role="option" data-wp-bind--aria-selected="state.isPrivacyFollowers">
							<button type="button"
								class="bn-composer__privacy-opt"
								data-privacy="followers"
								data-wp-on--click="actions.setPrivacy">
								<?php buddynext_icon( 'users' ); ?>
								<span class="bn-composer__privacy-opt-label">
									<strong><?php esc_html_e( 'Followers', 'buddynext' ); ?></strong>
									<small><?php esc_html_e( 'Only people who follow you.', 'buddynext' ); ?></small>
								</span>
							</button>
						</li>
						<li role="option" data-wp-bind--aria-selected="state.isPrivacyConnections">
							<button type="button"
								class="bn-composer__privacy-opt"
								data-privacy="connections"
								data-wp-on--click="actions.setPrivacy">
								<?php buddynext_icon( 'user-check' ); ?>
								<span class="bn-composer__privacy-opt-label">
									<strong><?php esc_html_e( 'Connections', 'buddynext' ); ?></strong>
									<small><?php esc_html_e( 'Only your accepted connections.', 'buddynext' ); ?></small>
								</span>
							</button>
						</li>
						<li role="option" data-wp-bind--aria-selected="state.isPrivacyPrivate">
							<button type="button"
								class="bn-composer__privacy-opt"
								data-privacy="private"
								data-wp-on--click="actions.setPrivacy">
								<?php buddynext_icon( 'lock' ); ?>
								<span class="bn-composer__privacy-opt-label">
									<strong><?php esc_html_e( 'Only me', 'buddynext' ); ?></strong>
									<small><?php esc_html_e( 'Saved for your eyes only.', 'buddynext' ); ?></small>
								</span>
							</button>
						</li>
					</ul>
				</div>
			<?php endif; ?>

			<div class="bn-composer__draft"
				data-wp-bind--hidden="state.draftStatusHidden"
				role="status"
				aria-live="polite"
				hidden>
				<span class="bn-composer__draft-status" data-wp-text="context.draftStatus"></span>
				<button
					class="bn-composer__draft-discard"
					type="button"
					data-wp-on--click="actions.discardDraft"
					data-wp-bind--hidden="state.draftDiscardHidden"
					aria-label="<?php esc_attr_e( 'Discard draft', 'buddynext' ); ?>"
					title="<?php esc_attr_e( 'Discard draft', 'buddynext' ); ?>"
					hidden>
					<?php buddynext_icon( 'trash' ); ?>
				</button>
			</div>

			<button
				class="bn-btn bn-composer__submit"
				type="button"
				data-variant="primary"
				data-size="sm"
				data-wp-on--click="actions.submit"
				data-wp-bind--disabled="state.submitting">
				<span class="bn-composer__submit-label"
					data-wp-text="state.submitLabel"><?php esc_html_e( 'Share', 'buddynext' ); ?></span>
			</button>

		</div>

	</div>

	<?php
	// Event modal renders INSIDE the composer's Interactivity root so it shares
	// the same buddynext/post-composer context (eventOpen lives there). As a
	// sibling island it had its own empty context and never received the
	// openEvent() state change, so the modal stayed hidden — keep it nested.
	buddynext_get_template(
		'partials/composer-event-modal.php',
		array(
			'composer_user_id' => $composer_user_id,
		)
	);
	?>

</div>

<?php
/**
 * Composer-after sub-template injection point. Pro / 3rd-party plugins
 * render their own composer modals (AI helper, voice room, etc.) here.
 *
 * @param array $args Sanitized composer args.
 */
do_action( 'buddynext_composer_modals', $args );

do_action( 'buddynext_part_composer_after', $args );

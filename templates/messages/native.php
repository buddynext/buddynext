<?php
/**
 * Native messaging two-pane (BuddyNext-owned, consumes WPMediaVerse via mvs/v1).
 *
 * Renders the conversation rail + the active thread (or empty state) using the
 * dm-* partials and BuddyNext's own `buddynext/messages` Interactivity store.
 * No WPMediaVerse screens are embedded — data only.
 *
 * @package BuddyNext
 *
 * @var int    $active_conv_id Optional. Conversation to open. Default 0.
 * @var string $active_tab     Optional. Rail tab (all|unread|requests). Default 'all'.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Messages\MessagesData;
use BuddyNext\Core\PageRouter;

$active_conv_id = isset( $active_conv_id ) ? (int) $active_conv_id : 0;
$active_tab     = isset( $active_tab ) ? (string) $active_tab : 'all';

if ( ! MessagesData::available() ) :
	?>
	<div class="bn-messages-content" data-bn-main-edge="true">
		<div class="bn-card bn-dm-dep-notice" role="status">
			<div class="bn-dm-dep-notice__head">
				<span class="bn-dm-dep-notice__icon" aria-hidden="true"><?php buddynext_icon( 'message-circle' ); ?></span>
				<span class="bn-badge" data-tone="warn"><?php esc_html_e( 'Dependency required', 'buddynext' ); ?></span>
			</div>
			<h2 class="bn-dm-dep-notice__title"><?php esc_html_e( 'Direct messaging requires WPMediaVerse', 'buddynext' ); ?></h2>
			<p class="bn-dm-dep-notice__body">
				<?php esc_html_e( 'Install and activate the WPMediaVerse plugin to enable direct messaging in BuddyNext.', 'buddynext' ); ?>
			</p>
		</div>
	</div>
	<?php
	return;
endif;

$viewer = get_current_user_id();

// /messages/?conversation={id} — open an existing conversation. The rail items
// and tab links carry the open conversation as query state on the two-pane page
// (the canonical /messages/{id}/ path route passes it as $active_conv_id instead).
if ( $active_conv_id <= 0 ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$active_conv_id = absint( $_GET['conversation'] ?? 0 );
}

// /messages/?to={user_id} — open (or start) a direct conversation with a member.
// `to`, `recipient`, and the New-message picker all funnel through here; accept
// every alias so every "Message" entry point across the site lands correctly.
$bn_blocked_recipient = 0;
if ( $active_conv_id <= 0 ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$bn_to = absint( $_GET['to'] ?? ( $_GET['recipient'] ?? 0 ) );
	if ( $bn_to > 0 ) {
		$active_conv_id = MessagesData::open_with( $viewer, $bn_to );
		if ( $active_conv_id <= 0 ) {
			// Messaging this member is not allowed (DM access level, block, or
			// self) — surface a clear reason instead of a blank thread pane.
			$bn_blocked_recipient = $bn_to;
		}
	}
}

$helpers      = MessagesData::helpers();
$convs        = MessagesData::conversations( $viewer, $active_tab );
$thread       = $active_conv_id > 0 ? MessagesData::thread( $active_conv_id, $viewer ) : null;
$messages_url = PageRouter::messages_url();

$bn_ctx = wp_json_encode(
	array(
		'mvsRest'      => esc_url_raw( rest_url( 'mvs/v1' ) ),
		'bnRest'       => esc_url_raw( rest_url( 'buddynext/v1' ) ),
		'nonce'        => wp_create_nonce( 'wp_rest' ),
		'userId'       => $viewer,
		'composeOpen'  => false,
		'activeConvId' => $thread ? (int) $thread['conversation_id'] : 0,
		'replyToId'    => 0,
		'replyToText'  => '',
		'confirmOpen'      => false,
		'attachmentVisible' => false,
		'mediaPickerOpen'  => false,
		'attachmentId'     => 0,
		'attachmentName'   => '',
		'attachmentPreview' => '',
		'messagesUrl'  => $messages_url,
		'i18n'         => array(
			'composeHint' => __( 'Type a name to find someone to message.', 'buddynext' ),
			'composeNone' => __( 'No members found.', 'buddynext' ),
			'mediaEmpty'  => __( 'No photos yet — upload one to share.', 'buddynext' ),
		),
	)
);
?>
<div
	class="bn-messages-content bn-split bn-dm"
	data-bn-main-edge="true"
	data-wp-interactive="buddynext/messages"
	data-wp-context='<?php echo esc_attr( (string) $bn_ctx ); ?>'
>
	<?php
	buddynext_get_template(
		'parts/dm-rail.php',
		array_merge(
			$helpers,
			array(
				'pinned_conversations' => $convs['pinned'],
				'recent_conversations' => $convs['recent'],
				'active_conv_id'       => $active_conv_id,
				'active_tab'           => $active_tab,
				'unread_count'         => $convs['unread'],
				'request_count'        => $convs['requests'],
				'current_user_id'      => $viewer,
				'compose_url'          => $messages_url,
			)
		)
	);
	?>

	<section class="bn-split__main bn-dm-thread">
		<?php if ( $thread ) : ?>
			<div class="bn-dm-thread__inner" data-wp-init="callbacks.initThread">
				<?php
				$bn_initials = $helpers['initials_fn']( $thread['display_name'] );
				$bn_tone     = $helpers['tone_fn']( $thread['other_user_id'] );

				buddynext_get_template(
					'parts/dm-thread-header.php',
					array(
						'display_name'  => $thread['display_name'],
						'other_user_id' => $thread['other_user_id'],
						'is_online'     => $thread['is_online'],
						'tone'          => $bn_tone,
						'initials'      => $bn_initials,
						'avatar_html'   => $thread['avatar_html'],
						'profile_url'   => $thread['other_user_id'] ? PageRouter::profile_url( $thread['other_user_id'] ) : '',
						'back_url'      => $messages_url,
					)
				);

				buddynext_get_template(
					'parts/dm-thread-messages.php',
					array(
						'messages'        => $thread['messages'],
						'current_user_id' => $viewer,
						'thread_tone'     => $bn_tone,
						'thread_initials' => $bn_initials,
						'aria_label'      => __( 'Conversation messages', 'buddynext' ),
					)
				);

				if ( ! empty( $thread['is_request'] ) ) {
					// Pending request: accept before replying.
					buddynext_get_template(
						'parts/dm-request-banner.php',
						array( 'display_name' => $thread['display_name'] )
					);
				} else {
					buddynext_get_template(
						'parts/dm-composer.php',
						array( 'conversation_id' => (int) $thread['conversation_id'] )
					);
				}
				?>
			</div>
		<?php elseif ( $bn_blocked_recipient > 0 ) : ?>
			<?php $bn_blocked_user = get_userdata( $bn_blocked_recipient ); ?>
			<div class="bn-dm-empty" role="status">
				<span class="bn-dm-empty__icon" aria-hidden="true"><?php buddynext_icon( 'ban' ); ?></span>
				<h2 class="bn-dm-empty__title"><?php esc_html_e( 'You can’t message this member', 'buddynext' ); ?></h2>
				<p class="bn-dm-empty__body">
					<?php
					echo esc_html(
						$bn_blocked_user
							? sprintf(
								/* translators: %s: member display name. */
								__( '%s isn’t accepting messages from you right now.', 'buddynext' ),
								$bn_blocked_user->display_name
							)
							: __( 'This member isn’t accepting messages from you right now.', 'buddynext' )
					);
					?>
				</p>
				<a class="bn-btn" data-variant="ghost" data-size="sm" href="<?php echo esc_url( $messages_url ); ?>">
					<?php esc_html_e( 'Back to messages', 'buddynext' ); ?>
				</a>
			</div>
		<?php else : ?>
			<div class="bn-dm-empty">
				<span class="bn-dm-empty__icon" aria-hidden="true"><?php buddynext_icon( 'message-circle' ); ?></span>
				<h2 class="bn-dm-empty__title"><?php esc_html_e( 'Your messages', 'buddynext' ); ?></h2>
				<p class="bn-dm-empty__body"><?php esc_html_e( 'Select a conversation or start a new one.', 'buddynext' ); ?></p>
			</div>
		<?php endif; ?>
	</section>

	<?php buddynext_get_template( 'parts/dm-delete-modal.php' ); ?>

	<?php buddynext_get_template( 'parts/dm-compose-modal.php' ); ?>

	<?php buddynext_get_template( 'parts/dm-media-modal.php' ); ?>

	<?php // Cloned by the store onto client-rendered (sent/polled) message bubbles. ?>
	<template id="bn-dm-msg-actions-tpl"><?php buddynext_get_template( 'parts/dm-msg-actions.php' ); ?></template>
</div>

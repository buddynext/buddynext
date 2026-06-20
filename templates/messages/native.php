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
				<span class="bn-badge" data-tone="warn"><?php esc_html_e( 'Unavailable', 'buddynext' ); ?></span>
			</div>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<h2 class="bn-dm-dep-notice__title"><?php esc_html_e( 'Direct messaging requires WPMediaVerse', 'buddynext' ); ?></h2>
				<p class="bn-dm-dep-notice__body">
					<?php esc_html_e( 'Install and activate the WPMediaVerse plugin to enable direct messaging in BuddyNext. (This notice is only shown to administrators.)', 'buddynext' ); ?>
				</p>
			<?php else : ?>
				<h2 class="bn-dm-dep-notice__title"><?php esc_html_e( 'Messaging isn’t available right now', 'buddynext' ); ?></h2>
				<p class="bn-dm-dep-notice__body">
					<?php esc_html_e( 'Direct messaging is currently unavailable on this community. Please check back later.', 'buddynext' ); ?>
				</p>
			<?php endif; ?>
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
// `to` (directory/connections), `recipient` (members REST), and `with` (profile
// hero) are all in use across the site; accept every alias so every "Message"
// entry point lands correctly.
$bn_blocked_recipient = 0;
$bn_block_reason      = '';
if ( $active_conv_id <= 0 ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$bn_to = absint( $_GET['to'] ?? ( $_GET['recipient'] ?? ( $_GET['with'] ?? 0 ) ) );
	if ( $bn_to > 0 ) {
		$bn_open        = MessagesData::open_with_result( $viewer, $bn_to );
		$active_conv_id = (int) $bn_open['conversation_id'];
		if ( $active_conv_id <= 0 ) {
			// Messaging this member is not allowed (DM access level, block, or
			// self) — surface a reason-aware notice instead of a blank thread pane.
			$bn_blocked_recipient = $bn_to;
			$bn_block_reason      = (string) $bn_open['reason'];
		}
	}
}

$helpers = MessagesData::helpers( $viewer );
$convs   = MessagesData::conversations( $viewer, $active_tab );

// When the inbox is opened with no explicit conversation (and the member is not
// trying to reach a blocked/unreachable recipient), auto-open the most recent
// conversation instead of showing the empty "Your messages" placeholder. The
// rail is already ordered by last activity, so the first pinned (or, failing
// that, the first recent) row is the newest thread. With zero conversations
// both lists are empty, $active_conv_id stays 0, and the empty state still shows.
if ( $active_conv_id <= 0 && 0 === $bn_blocked_recipient ) {
	$bn_default_conv = (int) ( $convs['pinned'][0]['id'] ?? ( $convs['recent'][0]['id'] ?? 0 ) );
	if ( $bn_default_conv > 0 ) {
		$active_conv_id = $bn_default_conv;
	}
}

$thread       = $active_conv_id > 0 ? MessagesData::thread( $active_conv_id, $viewer ) : null;
$messages_url = PageRouter::messages_url();

$bn_ctx = wp_json_encode(
	array(
		'mvsRest'           => esc_url_raw( rest_url( 'mvs/v1' ) ),
		'mvsProRest'        => esc_url_raw( rest_url( 'mvs-pro/v1' ) ),
		'groupsEnabled'     => MessagesData::groups_enabled(),
		'bnRest'            => esc_url_raw( rest_url( 'buddynext/v1' ) ),
		'nonce'             => wp_create_nonce( 'wp_rest' ),
		'userId'            => $viewer,
		'composeOpen'       => false,
		'composeMode'       => 'dm',
		'groupName'         => '',
		'groupMembers'      => array(),
		'groupBusy'         => false,
		'activeConvId'      => $thread ? (int) $thread['conversation_id'] : 0,
		'activeIsGroup'     => $thread ? ! empty( $thread['is_group'] ) : false,
		'activeIsAdmin'     => $thread ? ! empty( $thread['is_admin'] ) : false,
		'activeGroupName'   => $thread ? (string) $thread['display_name'] : '',
		'activeMembers'     => ( $thread && ! empty( $thread['is_group'] ) ) ? $thread['participants'] : array(),
		'memberCount'       => $thread ? (int) ( $thread['member_count'] ?? 0 ) : 0,
		'groupPanelOpen'    => false,
		'groupAddOpen'      => false,
		// Conversation info panel (1:1) — recipient identity + safety actions.
		'infoPanelOpen'     => false,
		'recipientId'       => ( $thread && empty( $thread['is_group'] ) ) ? (int) $thread['other_user_id'] : 0,
		'recipientName'     => $thread ? (string) $thread['display_name'] : '',
		'recipientUrl'      => ( $thread && ! empty( $thread['other_user_id'] ) ) ? esc_url_raw( PageRouter::profile_url( (int) $thread['other_user_id'] ) ) : '',
		'infoBusy'          => false,
		'replyToId'         => 0,
		'replyToText'       => '',
		'confirmOpen'       => false,
		'attachmentVisible' => false,
		'mediaPickerOpen'   => false,
		'attachmentId'      => 0,
		'attachmentName'    => '',
		'attachmentPreview' => '',
		'messagesUrl'       => $messages_url,
		'i18n'              => array(
			'composeHint'       => __( 'Type a name to find someone to message.', 'buddynext' ),
			'composeNone'       => __( 'No members found.', 'buddynext' ),
			'mediaEmpty'        => __( 'No photos yet — upload one to share.', 'buddynext' ),
			'composeNewMessage' => __( 'New message', 'buddynext' ),
			'composeNewGroup'   => __( 'New group', 'buddynext' ),
			'groupCreateFailed' => __( 'Could not create the group. Please try again.', 'buddynext' ),
			'groupActionFailed' => __( 'Something went wrong. Please try again.', 'buddynext' ),
			'groupLeaveConfirm' => __( 'Leave this group?', 'buddynext' ),
			'groupLeaveBody'    => __( 'You will stop receiving messages from this conversation.', 'buddynext' ),
			'groupLeaveOk'      => __( 'Leave', 'buddynext' ),
			'groupLeft'         => __( 'You left the group.', 'buddynext' ),
			'roleAdmin'         => __( 'Admin', 'buddynext' ),
			'roleMember'        => __( 'Member', 'buddynext' ),
			'makeAdmin'         => __( 'Make admin', 'buddynext' ),
			'makeMember'        => __( 'Make member', 'buddynext' ),
		),
	)
);
?>
<div
	class="bn-messages-content bn-split bn-dm<?php echo $thread ? ' is-thread-open' : ''; ?>"
	data-bn-main-edge="true"
	data-wp-interactive="buddynext/messages"
	data-wp-context='<?php echo esc_attr( (string) $bn_ctx ); ?>'
	data-wp-init="callbacks.fitViewport"
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
						'is_group'      => ! empty( $thread['is_group'] ),
						'member_count'  => (int) ( $thread['member_count'] ?? 0 ),
					)
				);

				buddynext_get_template(
					'parts/dm-thread-messages.php',
					array(
						'messages'           => $thread['messages'],
						'current_user_id'    => $viewer,
						'thread_tone'        => $bn_tone,
						'thread_initials'    => $bn_initials,
						'thread_avatar_html' => $thread['avatar_html'],
						'aria_label'         => __( 'Conversation messages', 'buddynext' ),
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
			<?php
			$bn_blocked_user = get_userdata( $bn_blocked_recipient );
			$bn_blocked_name = $bn_blocked_user ? $bn_blocked_user->display_name : __( 'This member', 'buddynext' );
			// Map the engine's denial reason to a clear, member-facing sentence so
			// the sender understands WHY (blocked / restricted inbox / too new /
			// rate limited) rather than a single catch-all line. Reasons mirror
			// MessagingService::can_message(); the default covers dms_disabled and
			// any unknown/future code.
			switch ( $bn_block_reason ) {
				case 'blocked':
					$bn_block_message = sprintf(
						/* translators: %s: member display name. */
						__( 'You can no longer message %s.', 'buddynext' ),
						$bn_blocked_name
					);
					break;
				case 'mutual_follow_required':
				case 'connections_only':
					$bn_block_message = sprintf(
						/* translators: %s: member display name. */
						__( '%s only accepts messages from people they are connected with.', 'buddynext' ),
						$bn_blocked_name
					);
					break;
				case 'account_too_new':
					$bn_block_message = __( 'Your account is too new to message this member yet. Please try again later.', 'buddynext' );
					break;
				case 'rate_limited':
					$bn_block_message = __( 'You are starting conversations too quickly. Please wait a moment and try again.', 'buddynext' );
					break;
				default:
					$bn_block_message = sprintf(
						/* translators: %s: member display name. */
						__( '%s isn’t accepting messages from you right now.', 'buddynext' ),
						$bn_blocked_name
					);
					break;
			}
			?>
			<div class="bn-dm-empty" role="status">
				<span class="bn-dm-empty__icon" aria-hidden="true"><?php buddynext_icon( 'ban' ); ?></span>
				<h2 class="bn-dm-empty__title"><?php esc_html_e( 'You can’t message this member', 'buddynext' ); ?></h2>
				<p class="bn-dm-empty__body"><?php echo esc_html( $bn_block_message ); ?></p>
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

	<?php
	if ( MessagesData::groups_enabled() ) {
		buddynext_get_template( 'parts/dm-group-panel.php' );
	}
	?>

	<?php buddynext_get_template( 'parts/dm-media-modal.php' ); ?>

	<?php
	// Conversation info panel — 1:1 threads only (groups use the group panel).
	if ( $thread && empty( $thread['is_group'] ) ) {
		buddynext_get_template(
			'parts/dm-info-panel.php',
			array(
				'display_name'  => (string) $thread['display_name'],
				'other_user_id' => (int) $thread['other_user_id'],
				'profile_url'   => $thread['other_user_id'] ? PageRouter::profile_url( (int) $thread['other_user_id'] ) : '',
				'avatar_html'   => (string) $thread['avatar_html'],
				'initials'      => $helpers['initials_fn']( $thread['display_name'] ),
				'tone'          => $helpers['tone_fn']( $thread['other_user_id'] ),
			)
		);
	}
	?>

	<?php // Cloned by the store onto client-rendered (sent/polled) message bubbles. ?>
	<template id="bn-dm-msg-actions-tpl"><?php buddynext_get_template( 'parts/dm-msg-actions.php' ); ?></template>
</div>

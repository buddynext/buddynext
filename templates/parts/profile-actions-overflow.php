<?php
/**
 * BuddyNext template part: the profile action row's ⋯ overflow menu.
 *
 * One menu, three audiences. Share lives here rather than as its own popover:
 * it used to be a second anchored dropdown on the same row, with its own
 * trigger, its own outside-click branch and its own flip measurement, all to
 * hold two links. Folding it in removed a whole popover and shortened the row,
 * which is what put the remaining trigger near the fold in the first place.
 *
 * The trigger is `secondary` and it is LABELLED. As a bordered-but-wordless
 * three-dot mark it still failed the only test that matters here: a member or
 * an admin should not have to guess what a control does. Every button in this
 * row now says what it is, and the icon is decoration beside the word rather
 * than a substitute for it (owner directive 2026-08-29).
 *
 * @package BuddyNext
 *
 * @var string $profile_url Absolute URL of the profile (copy-link target).
 * @var string $mention_url Compose URL that mentions this member (share to feed).
 * @var bool   $show_safety Render Mute / Restrict / Block / Report. Requires an
 *                          account to act on, so it is off for logged-out
 *                          visitors and meaningless on your own profile.
 * @var string $edit_url    Non-empty renders "Edit profile" (moderator capability).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_ov_profile = isset( $profile_url ) ? (string) $profile_url : '';
$bn_ov_mention = isset( $mention_url ) ? (string) $mention_url : '';
$bn_ov_safety  = ! empty( $show_safety );
$bn_ov_edit    = isset( $edit_url ) ? (string) $edit_url : '';
?>
<div class="bn-more-menu-wrap" data-wp-class--is-open="context.moreMenuOpen" data-wp-class--is-flipped="context.moreMenuFlip">
	<button class="bn-btn bn-pf-more-trigger"
		data-variant="secondary"
		data-size="sm"
		aria-label="<?php esc_attr_e( 'More options', 'buddynext' ); ?>"
		aria-expanded="false"
		data-wp-on--click="actions.toggleMoreMenu"
		data-wp-bind--aria-expanded="context.moreMenuOpen">
		<?php buddynext_icon( 'more-horizontal' ); ?>
		<span><?php esc_html_e( 'More', 'buddynext' ); ?></span>
	</button>
	<div class="bn-more-menu" role="menu">
		<?php
		/*
		 * One share action, not two. The owner bar used actions.shareProfile and
		 * the member view used actions.copyProfileLink — same intent, and the
		 * former is strictly better: it opens the OS share sheet via
		 * navigator.share where that exists (which is the whole point on a phone)
		 * and falls back to the clipboard where it does not. So "Copy link" was
		 * the fallback masquerading as a separate feature.
		 */
		?>
		<?php if ( '' !== $bn_ov_profile ) : ?>
			<button class="bn-more-menu-item"
				type="button"
				role="menuitem"
				data-share-url="<?php echo esc_attr( $bn_ov_profile ); ?>"
				data-wp-on--click="actions.shareProfile">
				<?php buddynext_icon( 'share-2' ); ?>
				<span><?php esc_html_e( 'Share profile', 'buddynext' ); ?></span>
			</button>
		<?php endif; ?>

		<?php if ( '' !== $bn_ov_mention ) : ?>
			<a class="bn-more-menu-item" role="menuitem" href="<?php echo esc_url( $bn_ov_mention ); ?>">
				<?php buddynext_icon( 'message-circle' ); ?>
				<span><?php esc_html_e( 'Share to feed', 'buddynext' ); ?></span>
			</a>
		<?php endif; ?>

		<?php if ( '' !== $bn_ov_edit ) : ?>
			<?php // Edit this member's profile — holders of buddynext-profile/edit-any only. ?>
			<a class="bn-more-menu-item" role="menuitem" href="<?php echo esc_url( $bn_ov_edit ); ?>">
				<?php buddynext_icon( 'edit' ); ?>
				<span><?php esc_html_e( 'Edit profile', 'buddynext' ); ?></span>
			</a>
		<?php endif; ?>

		<?php if ( $bn_ov_safety ) : ?>
			<button class="bn-more-menu-item"
				role="menuitem"
				data-wp-on--click="actions.toggleMute"
				data-wp-text="state.muteLabel">
				<?php esc_html_e( 'Mute', 'buddynext' ); ?>
			</button>
			<button class="bn-more-menu-item"
				role="menuitem"
				data-wp-on--click="actions.toggleRestrict"
				data-wp-text="state.restrictLabel">
				<?php esc_html_e( 'Restrict', 'buddynext' ); ?>
			</button>
			<button class="bn-more-menu-item bn-more-menu-item--danger"
				role="menuitem"
				data-wp-on--click="actions.toggleBlock"
				data-wp-text="state.blockLabel">
				<?php esc_html_e( 'Block', 'buddynext' ); ?>
			</button>
			<button class="bn-more-menu-item"
				role="menuitem"
				data-wp-on--click="actions.openReport">
				<?php esc_html_e( 'Report', 'buddynext' ); ?>
			</button>
		<?php endif; ?>
	</div>
</div>

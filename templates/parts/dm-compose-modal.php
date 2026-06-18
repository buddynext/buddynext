<?php
/**
 * BuddyNext template part: dm-compose-modal.
 *
 * The "New message" recipient picker. A member-search field whose results are
 * fetched from buddynext/v1/members and rendered by the messages store.
 *
 * Two modes (the group mode is shown only when WPMediaVerse Pro is active, via
 * `context.groupsEnabled`):
 *   - Direct  : picking a member navigates to /messages/?to={id}, which
 *               find-or-creates the 1-to-1 conversation.
 *   - Group   : picking members adds them as chips; a name + "Create group"
 *               posts to mvs-pro/v1/groups and opens the new conversation.
 *
 * Visibility is bound to `context.composeOpen`; the active mode to
 * `context.composeMode` ('dm' | 'group'). Results are injected as DOM nodes by
 * the store, so result clicks are delegated via actions.onComposeResultClick.
 *
 * Used by: templates/messages/native.php.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div
	class="bn-modal-backdrop bn-dm-compose is-hidden"
	role="dialog"
	aria-modal="true"
	aria-labelledby="bn-dm-compose-title"
	data-wp-class--is-hidden="!context.composeOpen"
	data-wp-on--click="actions.closeCompose"
>
	<div class="bn-modal__panel" data-size="sm" data-wp-on--click="actions.stopPropagation">
		<div class="bn-modal__head">
			<h2 id="bn-dm-compose-title" class="bn-modal__title" data-wp-text="state.composeTitle"><?php esc_html_e( 'New message', 'buddynext' ); ?></h2>
			<button type="button" class="bn-modal__close" aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>" data-wp-on--click="actions.closeCompose">
				<?php buddynext_icon( 'x' ); ?>
			</button>
		</div>

		<div class="bn-modal__body">
			<?php /* Mode switch — only when group chat is available. */ ?>
			<div class="bn-dm-compose__modes" data-wp-bind--hidden="!context.groupsEnabled" role="tablist">
				<button type="button" class="bn-dm-compose__mode" data-wp-class--is-active="state.composeIsDm" data-wp-on--click="actions.setComposeDm"><?php esc_html_e( 'Direct message', 'buddynext' ); ?></button>
				<button type="button" class="bn-dm-compose__mode" data-wp-class--is-active="state.composeIsGroup" data-wp-on--click="actions.setComposeGroup"><?php esc_html_e( 'New group', 'buddynext' ); ?></button>
			</div>

			<?php /* Group-only: name + selected member chips. */ ?>
			<div class="bn-dm-compose__group" data-wp-bind--hidden="!state.composeIsGroup">
				<input
					type="text"
					class="bn-input bn-dm-compose__group-name"
					placeholder="<?php esc_attr_e( 'Group name (optional)', 'buddynext' ); ?>"
					aria-label="<?php esc_attr_e( 'Group name', 'buddynext' ); ?>"
					data-wp-on--input="actions.setGroupName"
				>
				<ul class="bn-dm-compose__chips" data-wp-bind--hidden="state.groupHasNoMembers" aria-label="<?php esc_attr_e( 'Selected members', 'buddynext' ); ?>">
					<template data-wp-each--member="context.groupMembers">
						<li class="bn-dm-compose__chip">
							<span data-wp-text="context.member.name"></span>
							<button type="button" class="bn-dm-compose__chip-x" aria-label="<?php esc_attr_e( 'Remove', 'buddynext' ); ?>" data-wp-on--click="actions.removeGroupMember" data-wp-bind--data-id="context.member.id">&times;</button>
						</li>
					</template>
				</ul>
			</div>

			<input
				type="search"
				id="bn-dm-compose-search"
				class="bn-input"
				placeholder="<?php esc_attr_e( 'Search members…', 'buddynext' ); ?>"
				autocomplete="off"
				aria-label="<?php esc_attr_e( 'Search members to message', 'buddynext' ); ?>"
				data-wp-on--input="actions.onComposeSearch"
			>
			<ul class="bn-dm-compose__results" role="listbox" data-wp-on--click="actions.onComposeResultClick">
				<li class="bn-dm-compose__hint" data-bn-compose-hint><?php esc_html_e( 'Type a name to find someone to message.', 'buddynext' ); ?></li>
			</ul>

			<?php /* Group-only: create action. */ ?>
			<div class="bn-dm-compose__group-actions" data-wp-bind--hidden="!state.composeIsGroup">
				<button type="button" class="bn-btn" data-variant="primary" data-size="sm" data-wp-bind--disabled="state.createGroupDisabled" data-wp-on--click="actions.createGroup">
					<?php esc_html_e( 'Create group', 'buddynext' ); ?>
				</button>
			</div>
		</div>
	</div>
</div>

<?php
/**
 * BuddyNext template part: dm-group-panel.
 *
 * The group "Members" panel — a modal over the thread that shows the roster and,
 * for group admins, the management controls. All actions go to mvs-pro/v1/groups
 * (rename, add/remove participant, set role, leave); the store updates the live
 * roster + member count from each response, so the panel and the thread header
 * stay in sync without a reload.
 *
 * Visibility is bound to `context.groupPanelOpen`. The roster is rendered
 * reactively from `context.activeMembers` (each enriched with role/self/manage
 * flags by MessagesData::roster()); admin-only controls are gated per row on
 * `context.m.can_manage` and globally on `context.activeIsAdmin`.
 *
 * Rendered (only when WPMediaVerse Pro is active) by templates/messages/native.php.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div
	class="bn-modal-backdrop bn-dm-group is-hidden"
	role="dialog"
	aria-modal="true"
	aria-labelledby="bn-dm-group-title"
	data-wp-class--is-hidden="!context.groupPanelOpen"
	data-wp-on--click="actions.closeGroupPanel"
>
	<div class="bn-modal__panel" data-size="sm" data-wp-on--click="actions.stopPropagation">
		<div class="bn-modal__head">
			<h2 id="bn-dm-group-title" class="bn-modal__title"><?php esc_html_e( 'Group members', 'buddynext' ); ?></h2>
			<button type="button" class="bn-modal__close" aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>" data-wp-on--click="actions.closeGroupPanel">
				<?php buddynext_icon( 'x' ); ?>
			</button>
		</div>

		<div class="bn-modal__body">
			<?php /* Rename — admins only. */ ?>
			<div class="bn-dm-group__rename" data-wp-bind--hidden="!context.activeIsAdmin">
				<label class="bn-dm-group__label" for="bn-dm-group-name"><?php esc_html_e( 'Group name', 'buddynext' ); ?></label>
				<div class="bn-dm-group__rename-row">
					<input type="text" id="bn-dm-group-name" class="bn-input" data-wp-bind--value="context.activeGroupName" data-wp-on--input="actions.onGroupNameInput">
					<button type="button" class="bn-btn" data-variant="secondary" data-size="sm" data-wp-bind--disabled="context.groupBusy" data-wp-on--click="actions.renameGroup"><?php esc_html_e( 'Save', 'buddynext' ); ?></button>
				</div>
			</div>

			<?php /* Roster. */ ?>
			<ul class="bn-dm-group__members" aria-label="<?php esc_attr_e( 'Group members', 'buddynext' ); ?>">
				<template data-wp-each--m="context.activeMembers">
					<li class="bn-dm-group__member">
						<span class="bn-dm-group__member-id">
							<span class="bn-dm-group__member-name" data-wp-text="context.m.name"></span>
							<span class="bn-badge bn-dm-group__member-role" data-tone="neutral" data-wp-text="context.m.role_label"></span>
						</span>
						<span class="bn-dm-group__member-actions" data-wp-bind--hidden="!context.m.can_manage">
							<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" data-wp-text="context.m.role_action_label" data-wp-bind--data-id="context.m.id" data-wp-bind--data-role="context.m.role" data-wp-on--click="actions.toggleMemberRole"></button>
							<button type="button" class="bn-btn bn-dm-group__remove" data-variant="ghost" data-size="sm" aria-label="<?php esc_attr_e( 'Remove member', 'buddynext' ); ?>" data-wp-bind--data-id="context.m.id" data-wp-on--click="actions.removeMember">
								<?php buddynext_icon( 'user-minus' ); ?>
							</button>
						</span>
					</li>
				</template>
			</ul>

			<?php /* Add member — admins only. */ ?>
			<div class="bn-dm-group__add" data-wp-bind--hidden="!context.activeIsAdmin">
				<label class="bn-dm-group__label" for="bn-dm-group-add"><?php esc_html_e( 'Add a member', 'buddynext' ); ?></label>
				<input type="search" id="bn-dm-group-add" class="bn-input" autocomplete="off" placeholder="<?php esc_attr_e( 'Search members…', 'buddynext' ); ?>" data-wp-on--input="actions.onGroupAddSearch">
				<ul class="bn-dm-group__add-results" role="listbox" data-wp-on--click="actions.onGroupAddResultClick"></ul>
			</div>

			<?php /* Leave — everyone. */ ?>
			<div class="bn-dm-group__foot">
				<button type="button" class="bn-btn" data-variant="danger" data-size="sm" data-wp-bind--disabled="context.groupBusy" data-wp-on--click="actions.leaveGroup">
					<?php buddynext_icon( 'log-out' ); ?>
					<span><?php esc_html_e( 'Leave group', 'buddynext' ); ?></span>
				</button>
			</div>
		</div>
	</div>
</div>

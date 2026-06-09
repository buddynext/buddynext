<?php
/**
 * BuddyNext template part: dm-compose-modal.
 *
 * The "New message" recipient picker. A member-search field whose results are
 * fetched from buddynext/v1/members and rendered by the messages store; picking
 * a member navigates to /messages/?to={id}, which find-or-creates the
 * conversation (honouring the DM-access permission model) or shows the
 * "can't message" notice.
 *
 * Visibility is bound to `context.composeOpen`. Results are injected as DOM
 * nodes by the store (not server-rendered), so result clicks are delegated via
 * actions.onComposeResultClick on the list.
 *
 * Used by: templates/messages/native.php.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div
	class="bn-modal-backdrop bn-dm-compose"
	role="dialog"
	aria-modal="true"
	aria-labelledby="bn-dm-compose-title"
	data-wp-class--is-hidden="!context.composeOpen"
	data-wp-on--click="actions.closeCompose"
>
	<div class="bn-modal__panel" data-size="sm" data-wp-on--click="actions.stopPropagation">
		<div class="bn-modal__head">
			<h2 id="bn-dm-compose-title" class="bn-modal__title"><?php esc_html_e( 'New message', 'buddynext' ); ?></h2>
			<button type="button" class="bn-modal__close" aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>" data-wp-on--click="actions.closeCompose">
				<?php buddynext_icon( 'x' ); ?>
			</button>
		</div>
		<div class="bn-modal__body">
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
		</div>
	</div>
</div>

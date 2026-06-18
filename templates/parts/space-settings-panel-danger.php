<?php
/**
 * BuddyNext template part: space-settings-panel-danger.
 *
 * Renders the "Danger zone" panel + the three confirmation modals
 * (transfer-ownership, delete-space, archive-space). Each modal is the
 * semantic owner of its action; they stay with this part.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var object $space       Required. Space row.
 * @var array  $permissions Required. Bundle:
 *   - `space_id`         (int)
 *   - `owner_id`         (int)
 *   - `space_name`       (string) Used by the delete-confirm modal.
 *   - `xfer_candidates`  (array<object>) Eligible new-owner rows (user_id, display_name).
 * @var array  $classes     Optional. Extra CSS classes appended to `.bn-card`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_space_settings_panel_danger_before', $args )
 *   - do_action( 'buddynext_part_space_settings_panel_danger_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_space_settings_panel_danger_args',    array $args )
 *   - apply_filters( 'buddynext_part_space_settings_panel_danger_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'space'       => isset( $space ) ? $space : null,
	'permissions' => isset( $permissions ) && is_array( $permissions ) ? $permissions : array(),
	'classes'     => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_space_settings_panel_danger_args', $args );

$bn_space = $args['space'];
if ( ! $bn_space ) {
	return;
}

$bn_perms           = (array) $args['permissions'];
$bn_space_id        = isset( $bn_perms['space_id'] ) ? (int) $bn_perms['space_id'] : 0;
$bn_space_name      = isset( $bn_perms['space_name'] ) ? (string) $bn_perms['space_name'] : '';
$bn_xfer_candidates = isset( $bn_perms['xfer_candidates'] ) && is_array( $bn_perms['xfer_candidates'] )
	? $bn_perms['xfer_candidates']
	: array();

$bn_classes = array_merge( array( 'bn-card', 'bn-space-settings__panel' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_space_settings_panel_danger_classes', $bn_classes, $args );
$bn_class   = trim(
	implode(
		' ',
		array_unique(
			array_filter(
				$bn_classes,
				static function ( $c ) {
					return is_string( $c ) && '' !== $c;
				}
			)
		)
	)
);

do_action( 'buddynext_part_space_settings_panel_danger_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<header class="bn-space-settings__panel-head">
		<h2 class="bn-space-settings__panel-title bn-space-settings__panel-title--danger">
			<?php buddynext_icon( 'alert-triangle' ); ?> <?php esc_html_e( 'Danger zone', 'buddynext' ); ?>
		</h2>
		<p class="bn-space-settings__panel-desc"><?php esc_html_e( 'These actions are permanent and cannot be undone.', 'buddynext' ); ?></p>
	</header>

	<div class="bn-space-settings__danger-list">
		<div class="bn-space-settings__danger-row">
			<div>
				<div class="bn-space-settings__danger-title"><?php esc_html_e( 'Transfer ownership', 'buddynext' ); ?></div>
				<div class="bn-space-settings__danger-desc"><?php esc_html_e( 'Hand the space over to another active member. You will become a regular member.', 'buddynext' ); ?></div>
			</div>
			<button
				type="button"
				class="bn-btn"
				data-variant="secondary"
				data-size="md"
				data-wp-on--click="actions.openTransferOwnershipModal"
			><?php esc_html_e( 'Transfer ownership', 'buddynext' ); ?></button>
		</div>

		<div class="bn-space-settings__danger-row">
			<div>
				<div class="bn-space-settings__danger-title"><?php esc_html_e( 'Archive space', 'buddynext' ); ?></div>
				<div class="bn-space-settings__danger-desc"><?php esc_html_e( 'Make the space read-only. Members can still view posts but new activity is disabled.', 'buddynext' ); ?></div>
			</div>
			<button
				type="button"
				class="bn-btn"
				data-variant="secondary"
				data-size="md"
				data-wp-on--click="actions.openArchiveSpaceModal"
				data-space-id="<?php echo esc_attr( (string) $bn_space_id ); ?>"
			><?php esc_html_e( 'Archive space', 'buddynext' ); ?></button>
		</div>

		<div class="bn-space-settings__danger-row">
			<div>
				<div class="bn-space-settings__danger-title"><?php esc_html_e( 'Delete space', 'buddynext' ); ?></div>
				<div class="bn-space-settings__danger-desc"><?php esc_html_e( 'Permanently remove the space, its posts, and its memberships. This action cannot be undone.', 'buddynext' ); ?></div>
			</div>
			<button
				type="button"
				class="bn-btn"
				data-variant="danger"
				data-size="md"
				data-wp-on--click="actions.openDeleteSpaceConfirm"
			><?php esc_html_e( 'Delete space', 'buddynext' ); ?></button>
		</div>
	</div>
</div>

<!-- Transfer-ownership modal — REACTIVE: hidden bound to context.modalTransfer
	via the buddynext/spaces state getter; the opener action flips the flag, and
	data-bn-modal-close / Escape clear it. No imperative .hidden toggle. -->
<div
	class="bn-modal-backdrop"
	role="dialog"
	aria-modal="true"
	aria-labelledby="bn-transfer-title"
	hidden
	data-wp-bind--hidden="state.modalTransferHidden"
	data-bn-modal="transfer-ownership"
	data-bn-space-id="<?php echo esc_attr( (string) $bn_space_id ); ?>"
>
	<div class="bn-modal__panel" data-size="sm">
		<header class="bn-modal__head">
			<h2 class="bn-modal__title" id="bn-transfer-title"><?php esc_html_e( 'Transfer ownership', 'buddynext' ); ?></h2>
			<button
				type="button"
				class="bn-modal__close"
				aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
				data-bn-modal-close
			><?php buddynext_icon( 'x' ); ?></button>
		</header>
		<div class="bn-modal__body">
			<p><?php esc_html_e( 'Pick the new space owner. You will be demoted to a regular member.', 'buddynext' ); ?></p>
			<label class="bn-sr-only" for="bn_transfer_target">
				<?php esc_html_e( 'New owner', 'buddynext' ); ?>
			</label>
			<select id="bn_transfer_target" class="bn-select" data-bn-transfer-target>
				<option value=""><?php esc_html_e( '— Pick an active member —', 'buddynext' ); ?></option>
				<?php foreach ( $bn_xfer_candidates as $bn_xc ) : ?>
					<option value="<?php echo esc_attr( (string) $bn_xc->user_id ); ?>">
						<?php echo esc_html( $bn_xc->display_name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="bn-modal__error" data-bn-transfer-error hidden></p>
		</div>
		<div class="bn-modal__foot">
			<button
				type="button"
				class="bn-btn"
				data-variant="ghost"
				data-size="md"
				data-bn-modal-close
			><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
			<button
				type="button"
				class="bn-btn"
				data-variant="primary"
				data-size="md"
				data-wp-on--click="actions.transferOwnership"
			><?php esc_html_e( 'Transfer', 'buddynext' ); ?></button>
		</div>
	</div>
</div>

<?php
// Include the name-typing delete confirm modal.
buddynext_get_template(
	'partials/space-delete-confirm-modal.php',
	array(
		'space_id'   => $bn_space_id,
		'space_name' => $bn_space_name,
	)
);
?>

<!-- Delete-space confirm modal — REACTIVE: hidden bound to context.modalDelete. -->
<div
	class="bn-modal-backdrop"
	role="dialog"
	aria-modal="true"
	aria-labelledby="bn-delete-space-title"
	hidden
	data-wp-bind--hidden="state.modalDeleteHidden"
	data-bn-modal="delete-space"
>
	<div class="bn-modal__panel" data-tone="danger" data-size="sm">
		<header class="bn-modal__head">
			<h2 class="bn-modal__title" id="bn-delete-space-title"><?php esc_html_e( 'Delete this space?', 'buddynext' ); ?></h2>
			<button
				type="button"
				class="bn-modal__close"
				aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
				data-bn-modal-close
			><?php buddynext_icon( 'x' ); ?></button>
		</header>
		<div class="bn-modal__body">
			<p><?php esc_html_e( 'This will permanently delete the space, all of its posts, and all member relationships. This cannot be undone.', 'buddynext' ); ?></p>
		</div>
		<div class="bn-modal__foot">
			<button
				type="button"
				class="bn-btn"
				data-variant="ghost"
				data-size="md"
				data-bn-modal-close
			><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
			<button
				type="button"
				class="bn-btn"
				data-variant="danger"
				data-size="md"
				data-wp-on--click="actions.deleteSpace"
				data-space-id="<?php echo esc_attr( (string) $bn_space_id ); ?>"
			><?php esc_html_e( 'Delete permanently', 'buddynext' ); ?></button>
		</div>
	</div>
</div>

<!-- Archive-space confirm modal — REACTIVE: hidden bound to context.modalArchive. -->
<div
	class="bn-modal-backdrop"
	role="dialog"
	aria-modal="true"
	aria-labelledby="bn-archive-space-title"
	hidden
	data-wp-bind--hidden="state.modalArchiveHidden"
	data-bn-modal="archive-space"
>
	<div class="bn-modal__panel" data-size="sm">
		<header class="bn-modal__head">
			<h2 class="bn-modal__title" id="bn-archive-space-title"><?php esc_html_e( 'Archive this space?', 'buddynext' ); ?></h2>
			<button
				type="button"
				class="bn-modal__close"
				aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
				data-bn-modal-close
			><?php buddynext_icon( 'x' ); ?></button>
		</header>
		<div class="bn-modal__body">
			<p><?php esc_html_e( 'The space will become read-only. Members can still view past activity, but new posts and joins will be disabled.', 'buddynext' ); ?></p>
		</div>
		<div class="bn-modal__foot">
			<button
				type="button"
				class="bn-btn"
				data-variant="ghost"
				data-size="md"
				data-bn-modal-close
			><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
			<button
				type="button"
				class="bn-btn"
				data-variant="primary"
				data-size="md"
				data-wp-on--click="actions.archiveSpace"
				data-space-id="<?php echo esc_attr( (string) $bn_space_id ); ?>"
			><?php esc_html_e( 'Archive space', 'buddynext' ); ?></button>
		</div>
	</div>
</div>
<?php
do_action( 'buddynext_part_space_settings_panel_danger_after', $args );

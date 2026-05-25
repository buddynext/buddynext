<?php
/**
 * BuddyNext template part: member-block-modal.
 *
 * Renders the cross-surface "Block this member?" confirmation modal that
 * the directory + future surfaces open imperatively from the per-card
 * kebab menu. The modal is rendered OUTSIDE the
 * `data-wp-interactive="buddynext/members"` element so the embedded
 * `data-wp-on--click` bindings are bound by the parent store on the
 * outer page wrapper.
 *
 * Used by: templates/directory/members.php.
 *
 * @package BuddyNext
 *
 * @var string $nonce        Optional. REST nonce — included so callers can pass it
 *                           through (the modal itself doesn't render the value, but the
 *                           filter / `_args` hook exposes it to listeners). Default ''.
 * @var array  $i18n_strings Optional. Override map of label slugs => translated strings.
 *                           Recognized keys: title, intro, hide_posts, stop_contact,
 *                           remove_links, help, close_label, cancel, block. Default [].
 * @var array  $classes      Optional. Extra CSS classes appended to the backdrop. Default [].
 *
 * Fires:
 *   - do_action( 'buddynext_part_member_block_modal_before', $args )
 *   - do_action( 'buddynext_part_member_block_modal_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_member_block_modal_args',    array $args )
 *   - apply_filters( 'buddynext_part_member_block_modal_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'nonce'        => isset( $nonce ) ? (string) $nonce : '',
	'i18n_strings' => isset( $i18n_strings ) ? (array) $i18n_strings : array(),
	'classes'      => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_member_block_modal_args', $args );

$bn_classes = array_merge(
	array( 'bn-modal-backdrop', 'bn-pf-block-backdrop' ),
	array_filter( (array) $args['classes'], 'is_string' )
);
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_member_block_modal_classes', $bn_classes, $args );
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

$bn_i18n = array_merge(
	array(
		'title'        => __( 'Block this member?', 'buddynext' ),
		'intro'        => __( 'Blocking this person will:', 'buddynext' ),
		'hide_posts'   => __( 'Hide their posts and replies from your feed.', 'buddynext' ),
		'stop_contact' => __( 'Stop them from following you or sending you messages.', 'buddynext' ),
		'remove_links' => __( 'Remove any existing connection or follow between you.', 'buddynext' ),
		'help'         => __( 'You can unblock from your settings at any time.', 'buddynext' ),
		'close_label'  => __( 'Close', 'buddynext' ),
		'cancel'       => __( 'Cancel', 'buddynext' ),
		'block'        => __( 'Block', 'buddynext' ),
	),
	array_filter(
		(array) $args['i18n_strings'],
		static function ( $v ) {
			return is_string( $v ) && '' !== $v;
		}
	)
);

do_action( 'buddynext_part_member_block_modal_before', $args );
?>
<div
	class="<?php echo esc_attr( $bn_class ); ?>"
	role="dialog"
	aria-modal="true"
	aria-labelledby="bn-md-block-title"
	hidden
>
	<div class="bn-modal__panel" data-tone="danger" data-size="sm">
		<header class="bn-modal__head">
			<h2 class="bn-modal__title" id="bn-md-block-title"><?php echo esc_html( $bn_i18n['title'] ); ?></h2>
			<button
				class="bn-modal__close"
				type="button"
				aria-label="<?php echo esc_attr( $bn_i18n['close_label'] ); ?>"
				data-wp-on--click="actions.closeBlockConfirm"
			><?php buddynext_icon( 'x' ); ?></button>
		</header>
		<div class="bn-modal__body">
			<p><?php echo esc_html( $bn_i18n['intro'] ); ?></p>
			<ul class="bn-modal__list">
				<li><?php echo esc_html( $bn_i18n['hide_posts'] ); ?></li>
				<li><?php echo esc_html( $bn_i18n['stop_contact'] ); ?></li>
				<li><?php echo esc_html( $bn_i18n['remove_links'] ); ?></li>
			</ul>
			<p class="bn-modal__help"><?php echo esc_html( $bn_i18n['help'] ); ?></p>
		</div>
		<footer class="bn-modal__foot">
			<button
				class="bn-btn"
				type="button"
				data-variant="ghost"
				data-size="md"
				data-wp-on--click="actions.closeBlockConfirm"
			><?php echo esc_html( $bn_i18n['cancel'] ); ?></button>
			<button
				class="bn-btn"
				type="button"
				data-variant="danger"
				data-size="md"
				data-wp-on--click="actions.confirmBlock"
			><?php echo esc_html( $bn_i18n['block'] ); ?></button>
		</footer>
	</div>
</div>
<?php
do_action( 'buddynext_part_member_block_modal_after', $args );

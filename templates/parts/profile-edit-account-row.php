<?php
/**
 * BuddyNext template part: profile-edit-account-row.
 *
 * Renders a single Account-section row used on the edit-profile page.
 * Each row pairs a copy block (label + value + optional pending notice)
 * with one action — either an Interactivity-API button (for "Change",
 * "Sign out everywhere") or a plain `<a>` link (for the notification
 * preferences cross-link). Optionally a sibling inline form can be
 * supplied as `inline_form_html`; the form is wrapped in
 * `.bn-ep-account-form` and toggled by an Interactivity-API binding.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var string $row_id          Required. Stable key (used for hook payload).
 * @var string $label           Required. Visible label.
 * @var string $value           Required. Visible value / description copy.
 * @var string $pending_html    Optional. Raw HTML for a pending-state notice
 *                              shown under the value (caller-escaped).
 * @var string $cta_label       Required. CTA button/link text.
 * @var string $cta_variant     Optional. `bn-btn` variant. Default 'ghost'.
 * @var string $cta_size        Optional. `bn-btn` size. Default 'sm'.
 * @var string $cta_action      Optional. `data-wp-on--click` action name
 *                              for the button variant.
 * @var string $cta_href        Optional. When provided, renders as `<a>`
 *                              instead of `<button>` (used for the
 *                              notification-preferences cross-link).
 * @var string $cta_disabled    Optional. `data-wp-bind--disabled` expression.
 * @var string $inline_form_id  Optional. id for the inline form wrapper.
 * @var string $inline_form_html Optional. Pre-built HTML for the inline form
 *                              (caller-escaped).
 * @var string $inline_form_visible_when Optional. Interactivity expression
 *                              controlling visibility — passed as
 *                              `data-wp-bind--hidden="!<expr>"`.
 * @var array  $classes         Optional. Extra CSS classes on the row.
 *
 * Fires:
 *   - do_action( 'buddynext_part_profile_edit_account_row_before', $args )
 *   - do_action( 'buddynext_part_profile_edit_account_row_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_profile_edit_account_row_args',    array $args )
 *   - apply_filters( 'buddynext_part_profile_edit_account_row_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'row_id'                   => isset( $row_id ) ? (string) $row_id : '',
	'label'                    => isset( $label ) ? (string) $label : '',
	'value'                    => isset( $value ) ? (string) $value : '',
	'pending_html'             => isset( $pending_html ) ? (string) $pending_html : '',
	'cta_label'                => isset( $cta_label ) ? (string) $cta_label : '',
	'cta_variant'              => isset( $cta_variant ) ? (string) $cta_variant : 'ghost',
	'cta_size'                 => isset( $cta_size ) ? (string) $cta_size : 'sm',
	'cta_action'               => isset( $cta_action ) ? (string) $cta_action : '',
	'cta_href'                 => isset( $cta_href ) ? (string) $cta_href : '',
	'cta_disabled'             => isset( $cta_disabled ) ? (string) $cta_disabled : '',
	'inline_form_id'           => isset( $inline_form_id ) ? (string) $inline_form_id : '',
	'inline_form_html'         => isset( $inline_form_html ) ? (string) $inline_form_html : '',
	'inline_form_visible_when' => isset( $inline_form_visible_when ) ? (string) $inline_form_visible_when : '',
	'classes'                  => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_profile_edit_account_row_args', $args );

$bn_label = (string) $args['label'];
if ( '' === $bn_label ) {
	return;
}

$bn_classes = array_merge( array( 'bn-ep-account-row' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_profile_edit_account_row_classes', $bn_classes, $args );
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

$bn_value     = (string) $args['value'];
$bn_pending   = (string) $args['pending_html'];
$bn_cta_label = (string) $args['cta_label'];
$bn_cta_var   = (string) $args['cta_variant'];
$bn_cta_size  = (string) $args['cta_size'];
$bn_cta_act   = (string) $args['cta_action'];
$bn_cta_href  = (string) $args['cta_href'];
$bn_cta_disab = (string) $args['cta_disabled'];

do_action( 'buddynext_part_profile_edit_account_row_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<div class="bn-ep-account-copy">
		<div class="bn-ep-account-label"><?php echo esc_html( $bn_label ); ?></div>
		<div class="bn-ep-account-value"><?php echo esc_html( $bn_value ); ?></div>
		<?php
		if ( '' !== $bn_pending ) {
			// Caller-supplied HTML; caller is responsible for escaping.
			echo $bn_pending; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
	</div>
	<?php if ( '' !== $bn_cta_href ) : ?>
		<a class="bn-btn"
			data-variant="<?php echo esc_attr( $bn_cta_var ); ?>"
			data-size="<?php echo esc_attr( $bn_cta_size ); ?>"
			href="<?php echo esc_url( $bn_cta_href ); ?>">
			<?php echo esc_html( $bn_cta_label ); ?>
		</a>
	<?php elseif ( '' !== $bn_cta_label ) : ?>
		<button type="button"
			class="bn-btn"
			data-variant="<?php echo esc_attr( $bn_cta_var ); ?>"
			data-size="<?php echo esc_attr( $bn_cta_size ); ?>"
			<?php
			if ( '' !== $bn_cta_act ) :
				?>
				data-wp-on--click="<?php echo esc_attr( $bn_cta_act ); ?>"<?php endif; ?>
			<?php
			if ( '' !== $bn_cta_disab ) :
				?>
				data-wp-bind--disabled="<?php echo esc_attr( $bn_cta_disab ); ?>"<?php endif; ?>>
			<?php echo esc_html( $bn_cta_label ); ?>
		</button>
	<?php endif; ?>
</div>
<?php
if ( '' !== (string) $args['inline_form_html'] ) :
	$bn_inline_id   = (string) $args['inline_form_id'];
	$bn_inline_when = (string) $args['inline_form_visible_when'];
	?>
	<div class="bn-ep-account-form"
		<?php
		if ( '' !== $bn_inline_id ) :
			?>
			id="<?php echo esc_attr( $bn_inline_id ); ?>"<?php endif; ?>
		<?php
		if ( '' !== $bn_inline_when ) :
			?>
			data-wp-bind--hidden="!<?php echo esc_attr( $bn_inline_when ); ?>"<?php endif; ?>
		hidden>
		<?php
		// Caller-supplied HTML; caller is responsible for escaping.
		echo $args['inline_form_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</div>
	<?php
endif;
do_action( 'buddynext_part_profile_edit_account_row_after', $args );

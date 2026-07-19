<?php
/**
 * BuddyNext template part: sidebar-profile-education.
 *
 * Self-chromed "Education" sidebar card. Extracted verbatim from the former
 * `templates/partials/profile-right-sidebar.php` so ProfileSidebarProvider
 * can render it as a `chrome => false` widget descriptor. Empty
 * `$edu_entries` self-hides the card (no output).
 *
 * @package BuddyNext
 *
 * @var array    $edu_entries Non-empty education repeater entries.
 * @var callable $entry_fv    `fn(array $entry_fields, string $field_key): string` repeater field-value getter.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_pf_edu        = isset( $edu_entries ) && is_array( $edu_entries ) ? $edu_entries : array();
$bn_pf_noop_entry = static fn( array $entry_fields, string $field_key ): string => ''; // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- default fallback signature.
$bn_pf_entryfv    = isset( $entry_fv ) && is_callable( $entry_fv ) ? $entry_fv : $bn_pf_noop_entry;

if ( $bn_pf_edu ) :
	?>
<div class="bn-widget">
	<div class="bn-widget-title"><?php esc_html_e( 'Education', 'buddynext' ); ?></div>
	<?php foreach ( $bn_pf_edu as $entry_fields ) : ?>
		<?php
		$edu_institution = $bn_pf_entryfv( $entry_fields, 'edu_institution' );
		$edu_degree      = $bn_pf_entryfv( $entry_fields, 'edu_degree' );
		$edu_field_study = $bn_pf_entryfv( $entry_fields, 'edu_field' );
		if ( '' === $edu_institution ) {
			continue;
		}
		$edu_degree_line = implode( ', ', array_filter( array( $edu_degree, $edu_field_study ) ) );
		// Composed from the real edu_start_year / edu_end_year / edu_current
		// sub-fields — the old read targeted an edu_daterange key no field ever
		// wrote, so the sidebar never showed dates.
		$edu_date_display = \BuddyNext\Profile\FieldType::entry_daterange( $entry_fields, 'edu' );
		?>
		<div class="bn-repeater-entry">
			<div class="bn-repeater-entry__title"><?php echo esc_html( $edu_institution ); ?></div>
			<?php if ( $edu_degree_line ) : ?>
				<div class="bn-repeater-entry__sub"><?php echo esc_html( $edu_degree_line ); ?></div>
			<?php endif; ?>
			<?php if ( '' !== $edu_date_display ) : ?>
				<div class="bn-repeater-entry__dates"><?php echo wp_kses( $edu_date_display, array() ); ?></div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>
<?php endif; ?>

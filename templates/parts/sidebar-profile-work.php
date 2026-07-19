<?php
/**
 * BuddyNext template part: sidebar-profile-work.
 *
 * Self-chromed "Work Experience" sidebar card. Extracted verbatim from the
 * former `templates/partials/profile-right-sidebar.php` so
 * ProfileSidebarProvider can render it as a `chrome => false` widget
 * descriptor. Empty `$work_entries` self-hides the card (no output).
 *
 * @package BuddyNext
 *
 * @var array    $work_entries Non-empty work repeater entries.
 * @var callable $entry_fv     `fn(array $entry_fields, string $field_key): string` repeater field-value getter.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_pf_work       = isset( $work_entries ) && is_array( $work_entries ) ? $work_entries : array();
$bn_pf_noop_entry = static fn( array $entry_fields, string $field_key ): string => ''; // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- default fallback signature.
$bn_pf_entryfv    = isset( $entry_fv ) && is_callable( $entry_fv ) ? $entry_fv : $bn_pf_noop_entry;

if ( $bn_pf_work ) :
	?>
<div class="bn-widget">
	<div class="bn-widget-title"><?php esc_html_e( 'Work Experience', 'buddynext' ); ?></div>
	<?php foreach ( $bn_pf_work as $entry_fields ) : ?>
		<?php
		$we_company     = $bn_pf_entryfv( $entry_fields, 'work_company' );
		$we_title       = $bn_pf_entryfv( $entry_fields, 'work_title' );
		$we_location    = $bn_pf_entryfv( $entry_fields, 'work_location' );
		$we_description = $bn_pf_entryfv( $entry_fields, 'work_description' );
		if ( '' === $we_company && '' === $we_title ) {
			continue;
		}
		// Composed from the real work_start_date / work_end_date / work_current
		// sub-fields — the old read targeted a work_daterange key no field ever
		// wrote, so the sidebar never showed dates.
		$we_date_display = \BuddyNext\Profile\FieldType::entry_daterange( $entry_fields, 'work' );
		?>
		<div class="bn-repeater-entry">
			<?php if ( $we_title ) : ?>
				<div class="bn-repeater-entry__title"><?php echo esc_html( $we_title ); ?></div>
			<?php endif; ?>
			<?php if ( $we_company ) : ?>
				<div class="bn-repeater-entry__sub"><?php echo esc_html( $we_company ); ?></div>
			<?php endif; ?>
			<?php if ( '' !== $we_location ) : ?>
				<div class="bn-repeater-entry__sub"><?php echo esc_html( $we_location ); ?></div>
			<?php endif; ?>
			<?php if ( '' !== $we_date_display ) : ?>
				<div class="bn-repeater-entry__dates"><?php echo wp_kses( $we_date_display, array() ); ?></div>
			<?php endif; ?>
			<?php if ( $we_description ) : ?>
				<div class="bn-repeater-entry__desc"><?php echo wp_kses_post( $we_description ); ?></div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>
<?php endif; ?>

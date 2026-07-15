<?php
/**
 * BuddyNext profile about cards (Work / Education / Interests timeline).
 *
 * Extracted from `templates/profile/view.php` so the composer can stay
 * thin. Renders between the hero and the tab bar.
 *
 * Expected scope variables (passed via `buddynext_get_template()`):
 *
 * @var array    $work_entries
 * @var array    $edu_entries
 * @var array    $interest_chips Interest picks: array{name:string, url:string}[].
 * @var callable $entry_fv
 *
 * @package BuddyNext
 * @since   1.1.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_pf_work    = isset( $work_entries ) && is_array( $work_entries ) ? $work_entries : array();
$bn_pf_edu     = isset( $edu_entries ) && is_array( $edu_entries ) ? $edu_entries : array();
$bn_pf_int     = isset( $interest_chips ) && is_array( $interest_chips ) ? $interest_chips : array();
$bn_pf_noop    = static fn( array $entry_fields, string $field_key ): string => ''; // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- default fallback signature.
$bn_pf_entryfv = isset( $entry_fv ) && is_callable( $entry_fv ) ? $entry_fv : $bn_pf_noop;

/**
 * Render an entry's NON-curated sub-fields (anything an admin added to the
 * predefined group) as label/value detail rows below the timeline meta. The
 * curated keys are handled by the timeline markup above; without this loop a
 * custom field added to Work Experience / Education never displayed anywhere.
 *
 * @param array $entry_fields Entry field list.
 * @param array $curated      field_keys the timeline already renders.
 * @return string Escaped HTML ('' when the entry has no extra values).
 */
$bn_pf_extra_rows = static function ( array $entry_fields, array $curated ): string {
	$rows = '';
	foreach ( $entry_fields as $extra_field ) {
		if ( ! is_array( $extra_field ) || empty( $extra_field['field_key'] ) ) {
			continue;
		}
		if ( in_array( (string) $extra_field['field_key'], $curated, true ) ) {
			continue;
		}
		$extra_value = $extra_field['value'] ?? '';
		if ( is_array( $extra_value ) ? array() === $extra_value : '' === (string) $extra_value ) {
			continue;
		}
		$rows .= '<div class="bn-pf-detail"><dt class="bn-pf-detail__label">' . esc_html( (string) ( $extra_field['label'] ?? '' ) ) . '</dt><dd class="bn-pf-detail__value">' . \BuddyNext\Profile\FieldType::render_display( $extra_field, $extra_value ) . '</dd></div>';
	}
	return '' === $rows ? '' : '<dl class="bn-pf-detail-list bn-pf-timeline__extras">' . $rows . '</dl>';
};

$bn_pf_work_curated = array( 'work_company', 'work_title', 'work_location', 'work_start_date', 'work_end_date', 'work_current', 'work_description' );
$bn_pf_edu_curated  = array( 'edu_institution', 'edu_degree', 'edu_field', 'edu_start_year', 'edu_end_year', 'edu_current' );
?>
<?php if ( ! empty( $bn_pf_work ) ) : ?>
<section class="bn-card bn-pf-about-card bn-pf-work-card" aria-labelledby="bn-pf-work-title">
	<header class="bn-pf-about-card__header">
		<h2 class="bn-pf-about-card__title" id="bn-pf-work-title">
			<?php buddynext_icon( 'briefcase' ); ?>
			<span><?php esc_html_e( 'Work Experience', 'buddynext' ); ?></span>
		</h2>
	</header>
	<ol class="bn-pf-timeline">
		<?php
		foreach ( $bn_pf_work as $entry_fields ) :
			$we_company     = $bn_pf_entryfv( $entry_fields, 'work_company' );
			$we_title       = $bn_pf_entryfv( $entry_fields, 'work_title' );
			$we_location    = $bn_pf_entryfv( $entry_fields, 'work_location' );
			$we_daterange   = \BuddyNext\Profile\FieldType::entry_daterange( $entry_fields, 'work' );
			$we_description = $bn_pf_entryfv( $entry_fields, 'work_description' );
			$we_extras      = $bn_pf_extra_rows( $entry_fields, $bn_pf_work_curated );
			// Hide only a fully empty entry — one holding just a custom field
			// or dates must still render (it is the member's data).
			if ( '' === $we_company && '' === $we_title && '' === $we_location && '' === $we_daterange && '' === $we_description && '' === $we_extras ) {
				continue;
			}
			?>
		<li class="bn-pf-timeline__item">
			<span class="bn-pf-timeline__dot" aria-hidden="true"></span>
			<div class="bn-pf-timeline__body">
				<?php if ( '' !== $we_title ) : ?>
					<div class="bn-pf-timeline__title"><?php echo esc_html( $we_title ); ?></div>
				<?php endif; ?>
				<?php if ( '' !== $we_company ) : ?>
					<div class="bn-pf-timeline__sub"><?php echo esc_html( $we_company ); ?></div>
				<?php endif; ?>
				<div class="bn-pf-timeline__meta">
					<?php if ( '' !== $we_location ) : ?>
						<span><?php echo esc_html( $we_location ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $we_daterange ) : ?>
						<span><?php echo esc_html( $we_daterange ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( '' !== $we_description ) : ?>
					<p class="bn-pf-timeline__desc"><?php echo wp_kses_post( $we_description ); ?></p>
				<?php endif; ?>
				<?php echo $we_extras; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html/render_display above. ?>
			</div>
		</li>
		<?php endforeach; ?>
	</ol>
</section>
<?php endif; ?>

<?php if ( ! empty( $bn_pf_edu ) ) : ?>
<section class="bn-card bn-pf-about-card bn-pf-edu-card" aria-labelledby="bn-pf-edu-title">
	<header class="bn-pf-about-card__header">
		<h2 class="bn-pf-about-card__title" id="bn-pf-edu-title">
			<?php buddynext_icon( 'graduation-cap' ); ?>
			<span><?php esc_html_e( 'Education', 'buddynext' ); ?></span>
		</h2>
	</header>
	<ol class="bn-pf-timeline">
		<?php
		foreach ( $bn_pf_edu as $entry_fields ) :
			$edu_institution = $bn_pf_entryfv( $entry_fields, 'edu_institution' );
			$edu_degree      = $bn_pf_entryfv( $entry_fields, 'edu_degree' );
			$edu_field_study = $bn_pf_entryfv( $entry_fields, 'edu_field' );
			$edu_daterange   = \BuddyNext\Profile\FieldType::entry_daterange( $entry_fields, 'edu' );
			$edu_extras      = $bn_pf_extra_rows( $entry_fields, $bn_pf_edu_curated );
			$edu_degree_line = implode( ', ', array_filter( array( $edu_degree, $edu_field_study ) ) );
			// Hide only a fully empty entry — custom-field-only or dates-only
			// entries must still render.
			if ( '' === $edu_institution && '' === $edu_degree_line && '' === $edu_daterange && '' === $edu_extras ) {
				continue;
			}
			?>
		<li class="bn-pf-timeline__item">
			<span class="bn-pf-timeline__dot" aria-hidden="true"></span>
			<div class="bn-pf-timeline__body">
				<?php if ( '' !== $edu_institution ) : ?>
					<div class="bn-pf-timeline__title"><?php echo esc_html( $edu_institution ); ?></div>
				<?php endif; ?>
				<?php if ( '' !== $edu_degree_line ) : ?>
					<div class="bn-pf-timeline__sub"><?php echo esc_html( $edu_degree_line ); ?></div>
				<?php endif; ?>
				<?php if ( '' !== $edu_daterange ) : ?>
					<div class="bn-pf-timeline__meta">
						<span><?php echo esc_html( $edu_daterange ); ?></span>
					</div>
				<?php endif; ?>
				<?php echo $edu_extras; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html/render_display above. ?>
			</div>
		</li>
		<?php endforeach; ?>
	</ol>
</section>
<?php endif; ?>

<?php if ( ! empty( $bn_pf_int ) ) : ?>
<section class="bn-card bn-pf-about-card bn-pf-interests-card" aria-labelledby="bn-pf-interests-title">
	<header class="bn-pf-about-card__header">
		<h2 class="bn-pf-about-card__title" id="bn-pf-interests-title">
			<?php buddynext_icon( 'hash' ); ?>
			<span><?php esc_html_e( 'Community Interests', 'buddynext' ); ?></span>
		</h2>
	</header>
	<div class="bn-pf-tag-cloud">
		<?php
		// Each chip deep-links to the spaces directory filtered to that
		// category (?bn_cat=slug) — the interest is a discovery surface.
		foreach ( $bn_pf_int as $bn_pf_chip ) :
			$bn_pf_chip_name = (string) ( $bn_pf_chip['name'] ?? '' );
			$bn_pf_chip_url  = (string) ( $bn_pf_chip['url'] ?? '' );
			if ( '' === $bn_pf_chip_name ) {
				continue;
			}
			?>
			<?php if ( '' !== $bn_pf_chip_url ) : ?>
				<a class="bn-pf-tag-chip" href="<?php echo esc_url( $bn_pf_chip_url ); ?>">
					<?php echo esc_html( $bn_pf_chip_name ); ?>
				</a>
			<?php else : ?>
				<span class="bn-pf-tag-chip"><?php echo esc_html( $bn_pf_chip_name ); ?></span>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>

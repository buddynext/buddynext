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
 * @var array    $interests
 * @var callable $entry_fv
 *
 * @package BuddyNext
 * @since   1.1.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_pf_work    = isset( $work_entries ) && is_array( $work_entries ) ? $work_entries : array();
$bn_pf_edu     = isset( $edu_entries ) && is_array( $edu_entries ) ? $edu_entries : array();
$bn_pf_int     = isset( $interests ) && is_array( $interests ) ? $interests : array();
$bn_pf_noop    = static fn( array $entry_fields, string $field_key ): string => ''; // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- default fallback signature.
$bn_pf_entryfv = isset( $entry_fv ) && is_callable( $entry_fv ) ? $entry_fv : $bn_pf_noop;
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
			$we_daterange   = $bn_pf_entryfv( $entry_fields, 'work_daterange' );
			$we_description = $bn_pf_entryfv( $entry_fields, 'work_description' );
			if ( '' === $we_company && '' === $we_title ) {
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
			$edu_daterange   = $bn_pf_entryfv( $entry_fields, 'edu_daterange' );
			if ( '' === $edu_institution ) {
				continue;
			}
			$edu_degree_line = implode( ', ', array_filter( array( $edu_degree, $edu_field_study ) ) );
			?>
		<li class="bn-pf-timeline__item">
			<span class="bn-pf-timeline__dot" aria-hidden="true"></span>
			<div class="bn-pf-timeline__body">
				<div class="bn-pf-timeline__title"><?php echo esc_html( $edu_institution ); ?></div>
				<?php if ( '' !== $edu_degree_line ) : ?>
					<div class="bn-pf-timeline__sub"><?php echo esc_html( $edu_degree_line ); ?></div>
				<?php endif; ?>
				<?php if ( '' !== $edu_daterange ) : ?>
					<div class="bn-pf-timeline__meta">
						<span><?php echo esc_html( $edu_daterange ); ?></span>
					</div>
				<?php endif; ?>
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
		foreach ( $bn_pf_int as $interest_tag ) :
			$tag_slug = sanitize_title( (string) $interest_tag );
			$tag_url  = home_url( '/activity/hashtag/' . $tag_slug . '/' );
			?>
			<a class="bn-pf-tag-chip" href="<?php echo esc_url( $tag_url ); ?>">
				#<?php echo esc_html( $interest_tag ); ?>
			</a>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>

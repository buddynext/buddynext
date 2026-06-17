<?php
/**
 * BuddyNext profile right-sidebar widgets.
 *
 * Extracted from `templates/profile/view.php` so the composer can stay
 * thin. This partial emits the profile-side widgets that the shell
 * renders in the right column via `buddynext_right_sidebar`.
 *
 * Expected scope variables (passed via `buddynext_get_template()`):
 *
 * @var bool                        $is_own_profile
 * @var array<string,mixed>|null    $completion
 * @var array                       $social_links
 * @var array                       $work_entries
 * @var array                       $edu_entries
 * @var array                       $interests
 * @var array                       $member_spaces
 * @var callable                    $get_fv
 * @var callable                    $entry_fv
 *
 * @package BuddyNext
 * @since   1.1.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_pf_is_own     = isset( $is_own_profile ) ? (bool) $is_own_profile : false;
$bn_pf_comp       = isset( $completion ) ? $completion : null;
$bn_pf_social     = isset( $social_links ) && is_array( $social_links ) ? $social_links : array();
$bn_pf_work       = isset( $work_entries ) && is_array( $work_entries ) ? $work_entries : array();
$bn_pf_edu        = isset( $edu_entries ) && is_array( $edu_entries ) ? $edu_entries : array();
$bn_pf_int        = isset( $interests ) && is_array( $interests ) ? $interests : array();
$bn_pf_spaces     = isset( $member_spaces ) && is_array( $member_spaces ) ? $member_spaces : array();
$bn_pf_noop_fv    = static fn( string $group_key, string $field_key ): string => ''; // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- default fallback signature.
$bn_pf_noop_entry = static fn( array $entry_fields, string $field_key ): string => ''; // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- default fallback signature.
$bn_pf_get_fv     = isset( $get_fv ) && is_callable( $get_fv ) ? $get_fv : $bn_pf_noop_fv;
$bn_pf_entryfv    = isset( $entry_fv ) && is_callable( $entry_fv ) ? $entry_fv : $bn_pf_noop_entry;

if ( $bn_pf_is_own && null !== $bn_pf_comp ) :
	$edit_url = \BuddyNext\Core\PageRouter::edit_profile_url();
	?>
	<?php
	// Profile Strength widget (v2 prototype). The checklist is a curated set of
	// high-value profile actions, and the ring reflects how many of THESE the
	// member has completed — so finishing every listed task always lands on
	// 100% / "All set". The service-wide get_completion_score() counts every
	// flat field and still drives REST + gamification, but driving the ring off
	// it left the widget stuck below 100% with all visible tasks done, giving
	// the member no way to see which hidden field was missing.
	$bn_pf_tasks = array(
		array(
			'label' => __( 'Add a bio', 'buddynext' ),
			'done'  => '' !== $bn_pf_get_fv( 'basic_info', 'bio' ),
		),
		array(
			'label' => __( 'Add a tagline', 'buddynext' ),
			'done'  => '' !== $bn_pf_get_fv( 'basic_info', 'headline' ),
		),
		array(
			'label' => __( 'Set your location', 'buddynext' ),
			'done'  => '' !== $bn_pf_get_fv( 'basic_info', 'location' ),
		),
		array(
			'label' => __( 'Add your skills', 'buddynext' ),
			'done'  => ! empty( $bn_pf_int ),
		),
		array(
			'label' => __( 'Add work experience', 'buddynext' ),
			'done'  => ! empty( $bn_pf_work ),
		),
		array(
			'label' => __( 'Link an account', 'buddynext' ),
			'done'  => ! empty( $bn_pf_social ),
		),
	);

	$bn_pf_total = count( $bn_pf_tasks );
	$bn_pf_done  = count(
		array_filter(
			$bn_pf_tasks,
			static function ( $t ) {
				return ! empty( $t['done'] );
			}
		)
	);
	$bn_pf_togo  = $bn_pf_total - $bn_pf_done;
	$c_complete  = $bn_pf_total > 0 && $bn_pf_done === $bn_pf_total;

	$bn_ring_circ   = 150.80; // 2·π·r, r = 24 (matches the SVG below)
	$bn_ring_pct    = $bn_pf_total > 0 ? (int) round( ( $bn_pf_done / $bn_pf_total ) * 100 ) : 0;
	$bn_ring_offset = $bn_ring_circ * ( 1 - ( $bn_ring_pct / 100 ) );
	?>
	<div class="bn-widget">
		<div class="bn-widget-title"><?php esc_html_e( 'Profile Strength', 'buddynext' ); ?></div>

		<div class="bn-pf-ring-row">
			<div
				class="bn-pf-ring"
				role="img"
				aria-label="
				<?php
				/* translators: %d: profile completion percentage */
				echo esc_attr( sprintf( __( 'Profile %d%% complete', 'buddynext' ), $bn_ring_pct ) );
				?>
				"
			>
				<svg viewBox="0 0 56 56" aria-hidden="true" focusable="false">
					<circle class="bn-pf-ring__bg" cx="28" cy="28" r="24"></circle>
					<circle
						class="bn-pf-ring__fg"
						cx="28"
						cy="28"
						r="24"
						stroke-dasharray="<?php echo esc_attr( sprintf( '%.2f', $bn_ring_circ ) ); ?>"
						stroke-dashoffset="<?php echo esc_attr( sprintf( '%.2f', $bn_ring_offset ) ); ?>"
					></circle>
				</svg>
				<span class="bn-pf-ring__pct"><?php echo esc_html( (string) $bn_ring_pct ); ?></span>
			</div>
			<div class="bn-pf-ring__info">
				<?php if ( $c_complete ) : ?>
					<b><?php esc_html_e( 'All set', 'buddynext' ); ?></b>
					<span><?php esc_html_e( 'Your profile is complete.', 'buddynext' ); ?></span>
				<?php else : ?>
					<b>
						<?php
						/* translators: %d: number of remaining checklist items */
						echo esc_html( sprintf( _n( '%d to go', '%d to go', $bn_pf_togo, 'buddynext' ), $bn_pf_togo ) );
						?>
					</b>
					<span><?php esc_html_e( 'Finish these to complete your profile.', 'buddynext' ); ?></span>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( ! $c_complete ) : ?>
		<ul class="bn-pf-tasks">
			<?php foreach ( $bn_pf_tasks as $bn_pf_task ) : ?>
				<li class="bn-pf-task<?php echo ! empty( $bn_pf_task['done'] ) ? ' is-done' : ''; ?>">
					<span class="bn-pf-task__mark" aria-hidden="true">
						<?php
						if ( ! empty( $bn_pf_task['done'] ) ) {
							buddynext_icon( 'check' );
						}
						?>
					</span>
					<span class="bn-pf-task__label"><?php echo esc_html( (string) $bn_pf_task['label'] ); ?></span>
					<?php if ( empty( $bn_pf_task['done'] ) ) : ?>
						<a class="bn-pf-task__cta" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Add', 'buddynext' ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php if ( $bn_pf_social ) : ?>
<div class="bn-widget">
	<div class="bn-widget-title"><?php esc_html_e( 'Connect', 'buddynext' ); ?></div>
	<?php foreach ( $bn_pf_social as $field ) : ?>
		<div class="bn-field-row">
			<span class="bn-field-label"><?php echo esc_html( $field['label'] ); ?></span>
			<span class="bn-field-value">
				<a href="<?php echo esc_url( (string) ( $field['value'] ?? '' ) ); ?>"
					target="_blank" rel="noopener noreferrer me">
					<?php
					$parsed_host = wp_parse_url( (string) ( $field['value'] ?? '' ), PHP_URL_HOST );
					echo esc_html( $parsed_host ? $parsed_host : (string) ( $field['value'] ?? '' ) );
					?>
				</a>
			</span>
		</div>
	<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ( $bn_pf_work ) : ?>
<div class="bn-widget">
	<div class="bn-widget-title"><?php esc_html_e( 'Work Experience', 'buddynext' ); ?></div>
	<?php foreach ( $bn_pf_work as $entry_fields ) : ?>
		<?php
		$we_company     = $bn_pf_entryfv( $entry_fields, 'work_company' );
		$we_title       = $bn_pf_entryfv( $entry_fields, 'work_title' );
		$we_location    = $bn_pf_entryfv( $entry_fields, 'work_location' );
		$we_daterange   = $bn_pf_entryfv( $entry_fields, 'work_daterange' );
		$we_current     = $bn_pf_entryfv( $entry_fields, 'work_current' );
		$we_description = $bn_pf_entryfv( $entry_fields, 'work_description' );
		if ( '' === $we_company && '' === $we_title ) {
			continue;
		}
		$we_date_display = '' !== $we_daterange
			? ( '1' === $we_current
				? $we_daterange . ' &ndash; ' . esc_html__( 'Present', 'buddynext' )
				: $we_daterange )
			: ( '1' === $we_current ? esc_html__( 'Current', 'buddynext' ) : '' );
		?>
		<div class="bn-repeater-entry">
			<?php if ( $we_title ) : ?>
				<div class="bn-entry-title"><?php echo esc_html( $we_title ); ?></div>
			<?php endif; ?>
			<?php if ( $we_company ) : ?>
				<div class="bn-entry-sub"><?php echo esc_html( $we_company ); ?></div>
			<?php endif; ?>
			<?php if ( '' !== $we_location ) : ?>
				<div class="bn-entry-sub"><?php echo esc_html( $we_location ); ?></div>
			<?php endif; ?>
			<?php if ( '' !== $we_date_display ) : ?>
				<div class="bn-entry-meta"><?php echo wp_kses( $we_date_display, array() ); ?></div>
			<?php endif; ?>
			<?php if ( $we_description ) : ?>
				<div class="bn-entry-desc"><?php echo wp_kses_post( $we_description ); ?></div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ( $bn_pf_edu ) : ?>
<div class="bn-widget">
	<div class="bn-widget-title"><?php esc_html_e( 'Education', 'buddynext' ); ?></div>
	<?php foreach ( $bn_pf_edu as $entry_fields ) : ?>
		<?php
		$edu_institution = $bn_pf_entryfv( $entry_fields, 'edu_institution' );
		$edu_degree      = $bn_pf_entryfv( $entry_fields, 'edu_degree' );
		$edu_field_study = $bn_pf_entryfv( $entry_fields, 'edu_field' );
		$edu_daterange   = $bn_pf_entryfv( $entry_fields, 'edu_daterange' );
		$edu_current     = $bn_pf_entryfv( $entry_fields, 'edu_current' );
		if ( '' === $edu_institution ) {
			continue;
		}
		$edu_degree_line  = implode( ', ', array_filter( array( $edu_degree, $edu_field_study ) ) );
		$edu_date_display = '' !== $edu_daterange
			? ( '1' === $edu_current
				? $edu_daterange . ' &ndash; ' . esc_html__( 'Present', 'buddynext' )
				: $edu_daterange )
			: ( '1' === $edu_current ? esc_html__( 'Current', 'buddynext' ) : '' );
		?>
		<div class="bn-repeater-entry">
			<div class="bn-entry-title"><?php echo esc_html( $edu_institution ); ?></div>
			<?php if ( $edu_degree_line ) : ?>
				<div class="bn-entry-sub"><?php echo esc_html( $edu_degree_line ); ?></div>
			<?php endif; ?>
			<?php if ( '' !== $edu_date_display ) : ?>
				<div class="bn-entry-meta"><?php echo wp_kses( $edu_date_display, array() ); ?></div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ( $bn_pf_int ) : ?>
<div class="bn-widget">
	<div class="bn-widget-title"><?php esc_html_e( 'Interests', 'buddynext' ); ?></div>
	<div class="bn-skill-chips">
		<?php foreach ( $bn_pf_int as $interest ) : ?>
			<span class="bn-skill-chip"><?php echo esc_html( $interest ); ?></span>
		<?php endforeach; ?>
	</div>
</div>
<?php endif; ?>

<?php if ( $bn_pf_spaces ) : ?>
<div class="bn-widget">
	<div class="bn-widget-title"><?php esc_html_e( 'Member of', 'buddynext' ); ?></div>
	<?php
	foreach ( $bn_pf_spaces as $space ) :
		$bn_space_slug = isset( $space->slug ) ? (string) $space->slug : '';
		$bn_space_url  = '' !== $bn_space_slug
			? \BuddyNext\Core\PageRouter::spaces_url() . rawurlencode( $bn_space_slug ) . '/'
			: \BuddyNext\Core\PageRouter::space_url( (int) ( $space->id ?? 0 ) );
		?>
		<a class="bn-space-row" href="<?php echo esc_url( $bn_space_url ); ?>">
			<div class="bn-space-icon">
				<?php buddynext_icon( 'home' ); ?>
			</div>
			<div>
				<div class="bn-space-name"><?php echo esc_html( $space->name ); ?></div>
				<div class="bn-space-role"><?php echo esc_html( ucfirst( (string) $space->role ) ); ?></div>
			</div>
		</a>
	<?php endforeach; ?>
</div>
<?php endif; ?>

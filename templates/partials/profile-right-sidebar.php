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
	$c_pct      = (int) $bn_pf_comp['percent'];
	$c_complete = 100 === $c_pct;
	$edit_url   = \BuddyNext\Core\PageRouter::edit_profile_url();
	?>
	<div class="bn-widget">
		<div class="bn-widget-title"><?php esc_html_e( 'Profile Strength', 'buddynext' ); ?></div>
		<div class="bn-completion-bar-wrap">
			<div class="bn-completion-header">
				<span class="bn-completion-label">
					<?php
					echo $c_complete
						? esc_html__( 'Complete!', 'buddynext' )
						: esc_html__( 'Profile completion', 'buddynext' );
					?>
				</span>
				<span class="bn-completion-pct"><?php echo esc_html( $c_pct . '%' ); ?></span>
			</div>
			<div class="bn-completion-track">
				<div class="bn-completion-fill<?php echo $c_complete ? ' bn-complete' : ''; ?>"
					style="width:<?php echo esc_attr( $c_pct . '%' ); ?>"></div>
			</div>
		</div>
		<?php if ( ! $c_complete ) : ?>
		<div class="bn-prompt-cards">
			<?php if ( '' === $bn_pf_get_fv( 'basic_info', 'bio' ) ) : ?>
			<a href="<?php echo esc_url( $edit_url ); ?>" class="bn-prompt-card">
				<span class="bn-prompt-card-icon"><?php buddynext_icon( 'edit' ); ?></span>
				<?php esc_html_e( 'Add a bio', 'buddynext' ); ?>
			</a>
			<?php endif; ?>
			<?php if ( empty( $bn_pf_work ) ) : ?>
			<a href="<?php echo esc_url( $edit_url ); ?>" class="bn-prompt-card">
				<span class="bn-prompt-card-icon"><?php buddynext_icon( 'briefcase' ); ?></span>
				<?php esc_html_e( 'Add your work experience', 'buddynext' ); ?>
			</a>
			<?php endif; ?>
			<?php if ( empty( $bn_pf_int ) ) : ?>
			<a href="<?php echo esc_url( $edit_url ); ?>" class="bn-prompt-card">
				<span class="bn-prompt-card-icon"><?php buddynext_icon( 'layers' ); ?></span>
				<?php esc_html_e( 'Add your skills', 'buddynext' ); ?>
			</a>
			<?php endif; ?>
		</div>
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
	<?php foreach ( $bn_pf_spaces as $space ) : ?>
		<div class="bn-space-row">
			<div class="bn-space-icon">
				<?php buddynext_icon( 'home' ); ?>
			</div>
			<div>
				<div class="bn-space-name"><?php echo esc_html( $space->name ); ?></div>
				<div class="bn-space-role"><?php echo esc_html( ucfirst( (string) $space->role ) ); ?></div>
			</div>
		</div>
	<?php endforeach; ?>
</div>
<?php endif; ?>

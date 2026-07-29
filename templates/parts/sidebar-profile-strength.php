<?php
/**
 * BuddyNext template part: sidebar-profile-strength.
 *
 * Self-chromed "Profile Strength" sidebar card — own-profile only. Extracted
 * verbatim from the former `templates/partials/profile-right-sidebar.php` so
 * ProfileSidebarProvider can render it as a `chrome => false` widget
 * descriptor. The provider only appends this descriptor when
 * `is_own_profile` is true and `completion` is non-null; the `is_own_profile`
 * / `completion` guard below is kept anyway as defense in depth, and the
 * empty-tasks guard further down self-hides the card (empty output) when
 * every backing field was removed from the schema.
 *
 * @package BuddyNext
 *
 * @var bool                     $is_own_profile Whether the viewer owns this profile.
 * @var array<string,mixed>|null $completion     Non-null gate (see ProfileSidebarProvider).
 * @var array                    $skills         Skill strings, used only by the inline task-list fallback.
 * @var array                    $work_entries   Work entries, used only by the inline task-list fallback.
 * @var array                    $social_links   Social links, used only by the inline task-list fallback.
 * @var callable                 $get_fv         `fn(string $group_key, string $field_key): string` field-value getter.
 * @var array|null               $strength_tasks Canonical curated task list from ProfileService::get_strength() (preferred over the inline fallback).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_pf_is_own  = isset( $is_own_profile ) ? (bool) $is_own_profile : false;
$bn_pf_comp    = isset( $completion ) ? $completion : null;
$bn_pf_skills  = isset( $skills ) && is_array( $skills ) ? $skills : array();
$bn_pf_work    = isset( $work_entries ) && is_array( $work_entries ) ? $work_entries : array();
$bn_pf_social  = isset( $social_links ) && is_array( $social_links ) ? $social_links : array();
$bn_pf_noop_fv = static fn( string $group_key, string $field_key ): string => ''; // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- default fallback signature.
$bn_pf_get_fv  = isset( $get_fv ) && is_callable( $get_fv ) ? $get_fv : $bn_pf_noop_fv;

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
	//
	// view.php builds the canonical, EXISTENCE-FILTERED task list (a task whose
	// backing field/group was deleted from the schema is dropped, never shown
	// as forever-undone) and passes it as $strength_tasks — the same set that
	// drives the mobile hero chip. The inline build below is only the fallback
	// for a caller that did not supply the list.
	$bn_pf_tasks = isset( $strength_tasks ) && is_array( $strength_tasks )
		? $strength_tasks
		: array(
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
				'done'  => ! empty( $bn_pf_skills ),
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

	// No tasks at all (every backing field removed from the schema): skip the
	// widget entirely rather than rendering a 0% ring with an empty checklist.
	if ( ! empty( $bn_pf_tasks ) ) :
		?>
	<div class="bn-widget">
		<div class="bn-widget-title"><?php esc_html_e( 'Profile Strength', 'buddynext' ); ?></div>

		<div class="bn-pf-ring-row">
			<div
				class="bn-pf-ring"
				role="img"
				aria-label="
				<?php
				/* translators: %d: profile completion percentage (0-100). */
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
<?php endif; ?>

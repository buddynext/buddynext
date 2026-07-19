<?php
/**
 * Member-profile right-sidebar provider (Free core).
 *
 * Registers the seven self-chromed profile cards — profile strength (own
 * profile only), connect (social links), work experience, education,
 * interests, skills, member-of spaces — that formerly lived inline in
 * `templates/partials/profile-right-sidebar.php` as a single
 * `buddynext_right_sidebar` callback registered by `templates/profile/view.php`.
 * That partial's full context array now travels via
 * `Surface::set( 'profile', $bn_pf_sidebar_args )` instead, and this provider
 * reads it back through `Surface::context()`.
 *
 * Every descriptor is `chrome => false`: its `render` closure emits the
 * card's own `<div class="bn-widget">` wrapper (moved verbatim into
 * `templates/parts/sidebar-profile-*.php`), so SidebarRegistry echoes the
 * body raw instead of double-wrapping it. Each card's own empty-guard makes
 * it self-hiding when its underlying data is empty — mirrored here as an
 * `! empty()` check before the descriptor is even appended, so an empty
 * card never occupies a slot against the per-surface widget cap below.
 *
 * Profile Strength is the one provider-level hard gate: it is an
 * own-profile-only concept, so the descriptor is only appended when
 * `is_own_profile` is true and `completion` is non-null — a visitor must
 * never see another member's completion checklist, regardless of what a
 * caller happens to pass.
 *
 * @package BuddyNext\Sidebar\Providers
 */

declare( strict_types=1 );
namespace BuddyNext\Sidebar\Providers;

use BuddyNext\Sidebar\Surface;

/**
 * Member-profile sidebar widget descriptors.
 */
class ProfileSidebarProvider {

	/**
	 * Surfaces this provider's widgets appear on.
	 *
	 * @var array<int,string>
	 */
	private const SURFACES = array( 'profile' );

	/**
	 * Hooks the descriptor + max-widgets callbacks onto the sidebar registry filters.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'buddynext_sidebar_widgets', array( $this, 'widgets' ), 10, 2 );
		add_filter( 'buddynext_sidebar_max_widgets', array( $this, 'max_widgets' ), 10, 2 );
	}

	/**
	 * Appends the seven profile descriptors when the surface matches.
	 *
	 * @param array<int,array<string,mixed>> $descriptors Descriptors collected so far.
	 * @param string                         $surface     Current sidebar surface slug.
	 * @return array<int,array<string,mixed>>
	 */
	public function widgets( array $descriptors, string $surface ): array {
		if ( 'profile' !== $surface ) {
			return $descriptors;
		}

		$ctx = Surface::context();

		$is_own_profile = ! empty( $ctx['is_own_profile'] );
		$completion     = isset( $ctx['completion'] ) ? $ctx['completion'] : null;
		$social_links   = isset( $ctx['social_links'] ) && is_array( $ctx['social_links'] ) ? $ctx['social_links'] : array();
		$work_entries   = isset( $ctx['work_entries'] ) && is_array( $ctx['work_entries'] ) ? $ctx['work_entries'] : array();
		$edu_entries    = isset( $ctx['edu_entries'] ) && is_array( $ctx['edu_entries'] ) ? $ctx['edu_entries'] : array();
		$skills         = isset( $ctx['skills'] ) && is_array( $ctx['skills'] ) ? $ctx['skills'] : array();
		$interest_chips = isset( $ctx['interest_chips'] ) && is_array( $ctx['interest_chips'] ) ? $ctx['interest_chips'] : array();
		$member_spaces  = isset( $ctx['member_spaces'] ) && is_array( $ctx['member_spaces'] ) ? $ctx['member_spaces'] : array();
		$get_fv         = isset( $ctx['get_fv'] ) && is_callable( $ctx['get_fv'] ) ? $ctx['get_fv'] : null;
		$entry_fv       = isset( $ctx['entry_fv'] ) && is_callable( $ctx['entry_fv'] ) ? $ctx['entry_fv'] : null;
		$strength_tasks = isset( $ctx['strength_tasks'] ) && is_array( $ctx['strength_tasks'] ) ? $ctx['strength_tasks'] : null;

		// Card: Profile Strength. Own-profile-only — never shown to a visitor.
		// An empty curated task list (every backing field removed from the
		// schema) still self-hides via the part file's own guard.
		if ( $is_own_profile && null !== $completion ) {
			$descriptors[] = array(
				'id'       => 'profile-strength',
				'priority' => 10,
				'surfaces' => self::SURFACES,
				'chrome'   => false,
				'render'   => static function () use ( $is_own_profile, $completion, $skills, $work_entries, $social_links, $get_fv, $strength_tasks ): void {
					if ( ! function_exists( 'buddynext_get_template' ) ) {
						return;
					}
					buddynext_get_template(
						'parts/sidebar-profile-strength.php',
						array(
							'is_own_profile' => $is_own_profile,
							'completion'     => $completion,
							'skills'         => $skills,
							'work_entries'   => $work_entries,
							'social_links'   => $social_links,
							'get_fv'         => $get_fv,
							'strength_tasks' => $strength_tasks,
						)
					);
				},
			);
		}

		// Card: Connect (social links).
		if ( ! empty( $social_links ) ) {
			$descriptors[] = array(
				'id'       => 'profile-connect',
				'priority' => 20,
				'surfaces' => self::SURFACES,
				'chrome'   => false,
				'render'   => static function () use ( $social_links ): void {
					if ( ! function_exists( 'buddynext_get_template' ) ) {
						return;
					}
					buddynext_get_template(
						'parts/sidebar-profile-connect.php',
						array( 'social_links' => $social_links )
					);
				},
			);
		}

		// Card: Work experience.
		if ( ! empty( $work_entries ) ) {
			$descriptors[] = array(
				'id'       => 'profile-work',
				'priority' => 30,
				'surfaces' => self::SURFACES,
				'chrome'   => false,
				'render'   => static function () use ( $work_entries, $entry_fv ): void {
					if ( ! function_exists( 'buddynext_get_template' ) ) {
						return;
					}
					buddynext_get_template(
						'parts/sidebar-profile-work.php',
						array(
							'work_entries' => $work_entries,
							'entry_fv'     => $entry_fv,
						)
					);
				},
			);
		}

		// Card: Education.
		if ( ! empty( $edu_entries ) ) {
			$descriptors[] = array(
				'id'       => 'profile-education',
				'priority' => 40,
				'surfaces' => self::SURFACES,
				'chrome'   => false,
				'render'   => static function () use ( $edu_entries, $entry_fv ): void {
					if ( ! function_exists( 'buddynext_get_template' ) ) {
						return;
					}
					buddynext_get_template(
						'parts/sidebar-profile-education.php',
						array(
							'edu_entries' => $edu_entries,
							'entry_fv'    => $entry_fv,
						)
					);
				},
			);
		}

		// Card: Interests.
		if ( ! empty( $interest_chips ) ) {
			$descriptors[] = array(
				'id'       => 'profile-interests',
				'priority' => 50,
				'surfaces' => self::SURFACES,
				'chrome'   => false,
				'render'   => static function () use ( $interest_chips ): void {
					if ( ! function_exists( 'buddynext_get_template' ) ) {
						return;
					}
					buddynext_get_template(
						'parts/sidebar-profile-interests.php',
						array( 'interest_chips' => $interest_chips )
					);
				},
			);
		}

		// Card: Skills.
		if ( ! empty( $skills ) ) {
			$descriptors[] = array(
				'id'       => 'profile-skills',
				'priority' => 60,
				'surfaces' => self::SURFACES,
				'chrome'   => false,
				'render'   => static function () use ( $skills ): void {
					if ( ! function_exists( 'buddynext_get_template' ) ) {
						return;
					}
					buddynext_get_template(
						'parts/sidebar-profile-skills.php',
						array( 'skills' => $skills )
					);
				},
			);
		}

		// Card: Member of (joined spaces).
		if ( ! empty( $member_spaces ) ) {
			$descriptors[] = array(
				'id'       => 'profile-member-of',
				'priority' => 70,
				'surfaces' => self::SURFACES,
				'chrome'   => false,
				'render'   => static function () use ( $member_spaces ): void {
					if ( ! function_exists( 'buddynext_get_template' ) ) {
						return;
					}
					buddynext_get_template(
						'parts/sidebar-profile-member-of.php',
						array( 'member_spaces' => $member_spaces )
					);
				},
			);
		}

		return $descriptors;
	}

	/**
	 * Raises the per-surface widget cap to at least 7 on the profile
	 * surface so all seven cards can render together — the registry's
	 * default (6, `SidebarRegistry::render()`) would otherwise silently
	 * drop the lowest-priority card (`profile-member-of`) purely from the
	 * widget count, something the former unconditional partial never did.
	 *
	 * @param int    $max     Current max-widgets value.
	 * @param string $surface Current sidebar surface slug.
	 * @return int
	 */
	public function max_widgets( int $max, string $surface ): int {
		if ( 'profile' !== $surface ) {
			return $max;
		}
		return max( $max, 7 );
	}
}

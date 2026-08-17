<?php
/**
 * Member-profile right-sidebar provider (Free core).
 *
 * The profile sidebar carries only *feature / discovery* widgets — never
 * field-group cards. Field groups (Work Experience, Education, Skills, …) are
 * owner-controlled schema (the owner can delete a group, rename a sub-field, or
 * add a custom one), so their single home is the schema-driven About tab
 * (`templates/parts/profile/about-panel.php`). Duplicating specific groups here
 * would force hardcoded group + sub-field keys that break the moment the owner
 * edits their groups — the owner-freedom violation this split removes.
 *
 * The set is CONTEXT-AWARE — it differs by whether you are viewing your own
 * profile or someone else's, gated by `is_own_profile` (in `Surface::context()`)
 * via each descriptor's `condition`. This also guarantees another member's
 * profile is never an empty column: the viewer-centric discovery widgets
 * (people to connect with, what's happening) always apply.
 *
 *   Own profile   : Profile Strength (own-only) · People to connect with ·
 *                   What's happening · Member of.
 *   Other profile : People you may know · What's happening · Member of (theirs).
 *
 * Integrations extend this through the same `buddynext_sidebar_widgets` filter
 * with `surfaces => ['profile']` + an own-vs-other `condition` (e.g. a bridge
 * surfacing "their events" on a visited profile) — exactly how the Events widget
 * extends the feed. This provider ships the Free, non-integration baseline.
 *
 * Every descriptor is `chrome => false`: the render closure emits the card's own
 * `<div class="bn-widget"|"bn-sidebar-card">` wrapper (the reused feed partials),
 * so the registry echoes the body raw and each card self-hides when its data is
 * empty. The context array travels via `Surface::set( 'profile', $args )` from
 * `templates/profile/view.php`.
 *
 * @package BuddyNext\Sidebar\Providers
 */

declare( strict_types=1 );
namespace BuddyNext\Sidebar\Providers;

use BuddyNext\Sidebar\Surface;
use BuddyNext\Core\Container;

/**
 * Member-profile sidebar widget descriptors (feature / discovery widgets only).
 */
class ProfileSidebarProvider {

	/**
	 * Surfaces this provider's widgets appear on.
	 *
	 * @var array<int,string>
	 */
	private const SURFACES = array( 'profile' );

	/**
	 * Hooks the descriptor callback onto the sidebar registry filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'buddynext_sidebar_widgets', array( $this, 'widgets' ), 10, 2 );
	}

	/**
	 * The shared sidebar-widgets service (trending + suggested follows), or null
	 * when the feature is disabled — mirrors the resolution in FeedSidebarProvider.
	 *
	 * @return object|null
	 */
	private function widget_service() {
		if ( function_exists( 'buddynext_service' ) && Container::instance()->has( 'sidebar_widgets' ) ) {
			$svc = buddynext_service( 'sidebar_widgets' );
			return is_object( $svc ) ? $svc : null;
		}
		return null;
	}

	/**
	 * Appends the profile feature/discovery descriptors when the surface matches.
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
		$skills         = isset( $ctx['skills'] ) && is_array( $ctx['skills'] ) ? $ctx['skills'] : array();
		$work_entries   = isset( $ctx['work_entries'] ) && is_array( $ctx['work_entries'] ) ? $ctx['work_entries'] : array();
		$social_links   = isset( $ctx['social_links'] ) && is_array( $ctx['social_links'] ) ? $ctx['social_links'] : array();
		$member_spaces  = isset( $ctx['member_spaces'] ) && is_array( $ctx['member_spaces'] ) ? $ctx['member_spaces'] : array();
		$get_fv         = isset( $ctx['get_fv'] ) && is_callable( $ctx['get_fv'] ) ? $ctx['get_fv'] : null;
		$strength_tasks = isset( $ctx['strength_tasks'] ) && is_array( $ctx['strength_tasks'] ) ? $ctx['strength_tasks'] : null;

		$viewer_id = get_current_user_id();
		$service   = $this->widget_service();

		// Card: Profile Strength. OWN profile only — a visitor must never see
		// another member's completion checklist. Self-hides on an empty task list.
		//
		// mobile => false: the profile hero already carries `.bn-pf-completeness`,
		// a compact ring + percentage shown ONLY below 1025px as this card's
		// deliberate mobile counterpart (see bn-profile.css). Without the opt-out
		// the reflowed mobile column would put both on one screen — the redundant
		// pair that chip exists to avoid — and the hero chip is the better mobile
		// placement of the two, being at the top rather than after the content.
		if ( $is_own_profile && null !== $completion ) {
			$descriptors[] = array(
				'id'       => 'profile-strength',
				'priority' => 10,
				'surfaces' => self::SURFACES,
				'chrome'   => false,
				'mobile'   => false,
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

		// Card: People to connect with (own) / People you may know (other). Viewer-
		// centric suggested follows — applies on any profile, so another member's
		// profile is never an empty column. Self-hides when there are no suggestions.
		if ( null !== $service && $viewer_id > 0 && method_exists( $service, 'suggested_follows' ) ) {
			$suggested = (array) $service->suggested_follows( $viewer_id, 3 );
			if ( ! empty( $suggested ) ) {
				$members_url   = class_exists( '\BuddyNext\Core\PageRouter' ) ? \BuddyNext\Core\PageRouter::people_url() : '';
				$descriptors[] = array(
					'id'       => 'profile-people',
					'priority' => 20,
					'surfaces' => self::SURFACES,
					'chrome'   => false,
					'render'   => static function () use ( $suggested, $members_url ): void {
						if ( ! function_exists( 'buddynext_get_template' ) ) {
							return;
						}
						buddynext_get_template(
							'parts/sidebar-people-to-follow.php',
							array(
								'sbar_suggested'   => $suggested,
								'sbar_members_url' => $members_url,
							)
						);
					},
				);
			}
		}

		// Card: What's happening — trending topics/discussions. Viewer-centric,
		// applies on any profile. Self-hides when the rolling window is empty.
		if ( null !== $service && method_exists( $service, 'trending_hashtags' ) ) {
			$trending = (array) $service->trending_hashtags( 5 );
			if ( ! empty( $trending ) ) {
				$descriptors[] = array(
					'id'       => 'profile-whats-happening',
					'priority' => 30,
					'surfaces' => self::SURFACES,
					'chrome'   => false,
					'render'   => static function () use ( $trending ): void {
						if ( ! function_exists( 'buddynext_get_template' ) ) {
							return;
						}
						buddynext_get_template(
							'parts/sidebar-trending-topics.php',
							array( 'sbar_trending' => $trending )
						);
					},
				);
			}
		}

		// Card: Member of — the profile owner's spaces (public membership info; not
		// owner-editable field-group schema). Shows on own + other profiles.
		if ( ! empty( $member_spaces ) ) {
			$descriptors[] = array(
				'id'       => 'profile-member-of',
				'priority' => 40,
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
}

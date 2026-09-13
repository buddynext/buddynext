<?php
/**
 * Member-directory right-sidebar provider (Free core).
 *
 * Ships a full, ready-made sidebar for the member directory — BuddyNext has no
 * widget-add UI, so the column must carry its own relevant defaults. Registers:
 * "Online now" (presence, self-hides off-peak), "New members" (recently joined),
 * a daily-rotating "Member spotlight", plus the shared "People to follow" and
 * "What's happening" discovery cards. "New members" and "Member spotlight"
 * always render (guest and member) so the directory never collapses to a single
 * thin card when presence and personalization are empty. The spotlight rotates
 * by UTC date so a different member is featured each day with no owner config.
 *
 * The titled cards use the registry's DEFAULT chrome (no `chrome => false`), so
 * each `render` closure echoes ONLY the inner body and SidebarRegistry wraps it
 * in `parts/sidebar-card.php` using the descriptor's `title`/`icon`.
 *
 * @package BuddyNext\Sidebar\Providers
 */

declare( strict_types=1 );
namespace BuddyNext\Sidebar\Providers;

use BuddyNext\Core\PageRouter;
use BuddyNext\Core\Container;

/**
 * Member-directory sidebar widget descriptors.
 */
class MembersSidebarProvider {

	/**
	 * Surface this provider's widgets appear on.
	 *
	 * @var array<int,string>
	 */
	private const SURFACES = array( 'members' );

	/**
	 * Hooks the descriptor callback onto the sidebar registry filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'buddynext_sidebar_widgets', array( $this, 'widgets' ), 10, 2 );
	}

	/**
	 * Appends the member-directory descriptors when the surface matches.
	 *
	 * @param array<int,array<string,mixed>> $descriptors Descriptors collected so far.
	 * @param string                         $surface     Current sidebar surface slug.
	 * @return array<int,array<string,mixed>>
	 */
	public function widgets( array $descriptors, string $surface ): array {
		if ( 'members' !== $surface ) {
			return $descriptors;
		}

		$current_user_id = get_current_user_id();

		// Online-now: most-recently-active members within the online window, via
		// the same directory service (block-restrict aware) members.php reads.
		$online_rows = ( function_exists( 'buddynext_service' ) && is_object( buddynext_service( 'member_directory' ) ) )
			? buddynext_service( 'member_directory' )->online_now( $current_user_id, 6 )
			: array();

		if ( ! empty( $online_rows ) ) {
			$descriptors[] = array(
				'id'       => 'members-online-now',
				'priority' => 20,
				'surfaces' => self::SURFACES,
				'title'    => sprintf(
					/* translators: %s: number of online members */
					__( 'Online now (%s)', 'buddynext' ),
					number_format_i18n( count( $online_rows ) )
				),
				'icon'     => 'users',
				'render'   => static function () use ( $online_rows ): void {
					if ( ! function_exists( 'buddynext_get_template' ) ) {
						return;
					}
					echo '<ul class="bn-member-row-list">';
					foreach ( $online_rows as $row ) {
						$row_id = (int) $row['ID'];
						buddynext_get_template(
							'parts/sidebar-member-row.php',
							array(
								'row_user_id' => $row_id,
								'row_name'    => (string) $row['display_name'],
								'row_handle'  => (string) ( $row['handle'] ?? '' ),
								'row_url'     => PageRouter::profile_url( $row_id ),
								'row_avatar'  => (string) get_avatar_url( $row_id, array( 'size' => 40 ) ),
								'row_tone'    => \BuddyNext\Profile\AvatarService::identity_tone_for( (int) $row_id ),
								'row_online'  => true,
							)
						);
					}
					echo '</ul>';
				},
			);
		}

		// NOTE: no "By type" widget — the directory toolbar already has an "All
		// member types" dropdown facet, so a sidebar type list would duplicate the
		// same filter. Type filtering lives in the toolbar; the sidebar carries
		// presence (online-now) + discovery (below) instead.

		// Discovery widgets fill the directory sidebar below online-now (a short
		// card, so the column had dead space). Viewer-centric + self-chromed,
		// reusing the shared feed partials/service.
		$service = ( function_exists( 'buddynext_service' ) && Container::instance()->has( 'sidebar_widgets' ) )
			? buddynext_service( 'sidebar_widgets' )
			: null;

		if ( is_object( $service ) && $current_user_id > 0 && method_exists( $service, 'suggested_follows' ) ) {
			$suggested = (array) $service->suggested_follows( $current_user_id, 3 );
			if ( ! empty( $suggested ) ) {
				$members_url   = PageRouter::people_url();
				$descriptors[] = array(
					'id'       => 'members-people-to-follow',
					'priority' => 40,
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

		if ( is_object( $service ) && method_exists( $service, 'trending_hashtags' ) ) {
			$trending = (array) $service->trending_hashtags( 5 );
			if ( ! empty( $trending ) ) {
				$descriptors[] = array(
					'id'       => 'members-whats-happening',
					'priority' => 50,
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

		// Always-on discovery cards so the directory sidebar is never a single
		// thin card: presence (online-now) self-hides off-peak, so these carry the
		// column on their own. The member directory service is the one members.php
		// itself reads, so these are block-restrict aware by construction.
		$directory = ( function_exists( 'buddynext_service' ) && is_object( buddynext_service( 'member_directory' ) ) )
			? buddynext_service( 'member_directory' )
			: null;

		if ( is_object( $directory ) ) {
			// New members — the most recently joined, so the directory always shows
			// the community growing even to a logged-out visitor.
			$new_members = (array) ( $directory->list_members( $current_user_id, null, 5, array( 'sort' => 'newest' ) )['items'] ?? array() );
			if ( ! empty( $new_members ) ) {
				$descriptors[] = array(
					'id'       => 'members-new',
					'priority' => 30,
					'surfaces' => self::SURFACES,
					'title'    => __( 'New members', 'buddynext' ),
					'icon'     => 'user-plus',
					'render'   => function () use ( $new_members ): void {
						$this->render_member_rows( $new_members );
					},
				);
			}

			// Member spotlight — one featured member, ROTATED DAILY (deterministic by
			// UTC date) so the sidebar surfaces someone new every day and the column
			// stays fresh across return visits without any owner config. The pool is
			// the recent-members page; the date seed walks it one member per day.
			$pool = (array) ( $directory->list_members( $current_user_id, null, 30, array( 'sort' => 'newest' ) )['items'] ?? array() );
			if ( ! empty( $pool ) ) {
				$spotlight     = $pool[ (int) gmdate( 'Ymd' ) % count( $pool ) ];
				$descriptors[] = array(
					'id'       => 'members-spotlight',
					'priority' => 35,
					'surfaces' => self::SURFACES,
					'title'    => __( 'Member spotlight', 'buddynext' ),
					'icon'     => 'sparkles',
					'render'   => function () use ( $spotlight ): void {
						$this->render_spotlight( $spotlight );
					},
				);
			}
		}

		return $descriptors;
	}

	/**
	 * Render a compact list of member rows (avatar + name + @handle) reusing the
	 * shared sidebar-member-row partial — the same row shape as "Online now".
	 *
	 * @param array<int,array<string,mixed>> $rows Hydrated member cards from list_members().
	 * @return void
	 */
	private function render_member_rows( array $rows ): void {
		if ( ! function_exists( 'buddynext_get_template' ) ) {
			return;
		}
		echo '<ul class="bn-member-row-list">';
		foreach ( $rows as $row ) {
			$uid = (int) ( $row['user_id'] ?? 0 );
			if ( ! $uid ) {
				continue;
			}
			$user = get_userdata( $uid );
			buddynext_get_template(
				'parts/sidebar-member-row.php',
				array(
					'row_user_id' => $uid,
					'row_name'    => (string) ( $row['display_name'] ?? '' ),
					'row_handle'  => $user ? (string) $user->user_nicename : '',
					'row_url'     => PageRouter::profile_url( $uid ),
					'row_avatar'  => (string) ( $row['avatar_url'] ?? '' ),
					'row_tone'    => \BuddyNext\Profile\AvatarService::identity_tone_for( $uid ),
					'row_online'  => ! empty( $row['is_online'] ),
				)
			);
		}
		echo '</ul>';
	}

	/**
	 * Render the "Member spotlight" body: a single featured member row carrying a
	 * follower-count meta line and a Follow button, reusing the shared partial.
	 *
	 * @param array<string,mixed> $member Hydrated member card from list_members().
	 * @return void
	 */
	private function render_spotlight( array $member ): void {
		if ( ! function_exists( 'buddynext_get_template' ) ) {
			return;
		}
		$uid = (int) ( $member['user_id'] ?? 0 );
		if ( ! $uid ) {
			return;
		}
		$user      = get_userdata( $uid );
		$followers = (int) ( $member['follower_count'] ?? 0 );
		if ( $followers > 0 ) {
			/* translators: %s: formatted follower count. */
			$meta = sprintf( _n( '%s follower', '%s followers', $followers, 'buddynext' ), number_format_i18n( $followers ) );
		} else {
			$meta = ! empty( $member['bio'] ) ? wp_trim_words( (string) $member['bio'], 6, '…' ) : '';
		}
		echo '<ul class="bn-member-row-list">';
		buddynext_get_template(
			'parts/sidebar-member-row.php',
			array(
				'row_user_id' => $uid,
				'row_name'    => (string) ( $member['display_name'] ?? '' ),
				'row_handle'  => $user ? (string) $user->user_nicename : '',
				'row_url'     => PageRouter::profile_url( $uid ),
				'row_avatar'  => (string) ( $member['avatar_url'] ?? '' ),
				'row_tone'    => \BuddyNext\Profile\AvatarService::identity_tone_for( $uid ),
				'row_meta'    => $meta,
				'row_follow'  => true,
			)
		);
		echo '</ul>';
	}
}

<?php
/**
 * BuddyNext — Profile nav provider.
 *
 * Registers the built-in navigation for the member-profile surface into the
 * NavRegistry: the relationship metric row (Followers / Following / Connections)
 * and the primary content tabs (Posts / Scheduled / About / Replies / Media /
 * Likes) plus the Network sub-nav. Integration-owned items (Jetonomy Discussions,
 * Gamification Achievements) are registered by their own bridges through the same
 * `buddynext_register_nav` seam, so this provider owns ONLY the core surface.
 *
 * Each tab is a clean URL (`/members/{slug}/{tab}/`) + a `render` callable that
 * SSRs that one panel — the same content seam the space surface uses. Counts and
 * visibility are lazy callables resolved against the live NavContext, so one
 * declaration serves every profile (self or viewer) and only the active panel
 * queries (each render self-fetches).
 *
 * @package BuddyNext\Nav\Providers
 */

declare( strict_types=1 );

namespace BuddyNext\Nav\Providers;

use BuddyNext\Core\PageRouter;
use BuddyNext\Media\Galleries;
use BuddyNext\Media\MediaClient;
use BuddyNext\Nav\NavContext;
use BuddyNext\Nav\NavRegistry;

/**
 * Core nav provider for the `profile` surface.
 */
final class ProfileNav {

	/**
	 * Hook the provider onto the one-time registration action.
	 */
	public function register(): void {
		add_action( 'buddynext_register_nav', array( $this, 'register_items' ) );
	}

	/**
	 * Register the core profile metrics + primary tabs + network sub-nav.
	 *
	 * @param NavRegistry $registry The shared registry.
	 */
	public function register_items( NavRegistry $registry ): void {
		foreach ( $this->metrics() as $item ) {
			$registry->register( $item );
		}
		foreach ( $this->primary_tabs() as $item ) {
			$registry->register( $item );
		}
		foreach ( $this->network() as $item ) {
			$registry->register( $item );
		}
	}

	/**
	 * Clean-URL builder for a profile tab — /members/{slug}/{tab}/ (posts = base).
	 *
	 * @param int    $uid Profile user ID.
	 * @param string $tab Tab slug ('' = the posts/base URL).
	 * @return string
	 */
	private function tab_url( int $uid, string $tab ): string {
		$base = trailingslashit( PageRouter::profile_url( $uid ) );
		return '' === $tab || 'posts' === $tab ? $base : $base . $tab . '/';
	}

	/**
	 * The relationship metric row — display counts that deep-link (clean URL) to
	 * their people panel. The panel itself is owned by the matching Network sub-nav
	 * child's `render`; PanelRenderer resolves the active id to that child (or this
	 * metric) so the hero pill and the sub-nav reach the same screen.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function metrics(): array {
		return array(
			array(
				'id'          => 'followers',
				'surface'     => 'profile',
				'layer'       => 'metric',
				'label'       => __( 'Followers', 'buddynext' ),
				'count_label' => static fn( int $n ): string => _n( 'Follower', 'Followers', $n, 'buddynext' ),
				'url'         => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'followers' ),
				'priority'    => 10,
				'count'       => static fn( NavContext $c ): int => (int) buddynext_service( 'follows' )->follower_count( $c->subject_id ),
			),
			array(
				'id'       => 'following',
				'surface'  => 'profile',
				'layer'    => 'metric',
				'label'    => __( 'Following', 'buddynext' ),
				'url'      => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'following' ),
				'priority' => 20,
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'follows' )->following_count( $c->subject_id ),
			),
			array(
				'id'          => 'connections',
				'surface'     => 'profile',
				'layer'       => 'metric',
				'label'       => __( 'Connections', 'buddynext' ),
				'count_label' => static fn( int $n ): string => _n( 'Connection', 'Connections', $n, 'buddynext' ),
				'url'         => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'connections' ),
				'priority'    => 30,
				'count'       => static fn( NavContext $c ): int => (int) buddynext_service( 'connections' )->connection_count( $c->subject_id ),
			),
		);
	}

	/**
	 * The primary content tabs. Posts owns the post count (so the dedupe rule drops
	 * any metric that would duplicate it). Scheduled is owner-only; Media is gated
	 * on the media engine; About registers only when there is about content.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function primary_tabs(): array {
		return array(
			array(
				'id'       => 'posts',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => __( 'Posts', 'buddynext' ),
				'priority' => 10,
				'url'      => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'posts' ),
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'post_service' )->user_post_count( $c->subject_id ),
				'render'   => fn( NavContext $c ) => $this->render_posts( $c ),
			),
			array(
				'id'        => 'scheduled',
				'surface'   => 'profile',
				'layer'     => 'primary',
				'label'     => __( 'Scheduled', 'buddynext' ),
				'priority'  => 15,
				'after'     => 'posts',
				'condition' => static fn( NavContext $c ): bool => $c->is_self(),
				'url'       => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'scheduled' ),
				'count'     => static fn( NavContext $c ): int => (int) buddynext_service( 'post_service' )->user_scheduled_count( $c->subject_id ),
				'render'    => fn( NavContext $c ) => $this->render_scheduled( $c ),
			),
			array(
				'id'        => 'about',
				'surface'   => 'profile',
				'layer'     => 'primary',
				'label'     => __( 'About', 'buddynext' ),
				'priority'  => 12,
				'after'     => 'posts',
				'condition' => fn( NavContext $c ): bool => $this->has_about_content( $c ),
				'url'       => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'about' ),
				'render'    => fn( NavContext $c ) => $this->render_about( $c ),
			),
			array(
				'id'       => 'replies',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => __( 'Replies', 'buddynext' ),
				'priority' => 30,
				'url'      => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'replies' ),
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'post_service' )->reply_count( $c->subject_id ),
				'render'   => fn( NavContext $c ) => $this->render_replies( $c ),
			),
			array(
				'id'        => 'media',
				'surface'   => 'profile',
				'layer'     => 'primary',
				'label'     => __( 'Media', 'buddynext' ),
				'priority'  => 40,
				'condition' => static fn(): bool => MediaClient::available() && buddynext_integration_enabled( 'media', 'nav' ),
				'url'       => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'media' ),
				'count'     => static fn( NavContext $c ): int => (int) Galleries::user_media_count( $c->subject_id, $c->viewer_id ),
				'render'    => fn( NavContext $c ) => $this->render_media( $c ),
			),
			array(
				'id'       => 'likes',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => __( 'Likes', 'buddynext' ),
				'priority' => 50,
				'url'      => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'likes' ),
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'post_service' )->reaction_count( $c->subject_id ),
				'render'   => fn( NavContext $c ) => $this->render_likes( $c ),
			),
		);
	}

	/**
	 * The "Network" primary tab and its one-level sub-nav (Connections / Followers
	 * / Following). The parent owns no panel — landing on it deep-links to the first
	 * child (Connections). Each child carries the clean URL + the people-panel
	 * render; the hero metric pills deep-link to the same child URLs.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function network(): array {
		return array(
			array(
				'id'       => 'network',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => __( 'Network', 'buddynext' ),
				'icon'     => 'users',
				'priority' => 55,
				'url'      => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'connections' ),
			),
			array(
				'id'                => 'connections',
				'surface'           => 'profile',
				'layer'             => 'primary',
				'parent'            => 'network',
				'label'             => __( 'Connections', 'buddynext' ),
				'url'               => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'connections' ),
				'priority'          => 10,
				// Small, indexed people-count worth showing (network size); kept on at scale.
				'lightweight_count' => true,
				'count'             => static fn( NavContext $c ): int => (int) buddynext_service( 'connections' )->connection_count( $c->subject_id ),
				'render'            => fn( NavContext $c ) => $this->render_people( $c, 'connections' ),
			),
			array(
				'id'                => 'followers',
				'surface'           => 'profile',
				'layer'             => 'primary',
				'parent'            => 'network',
				'label'             => __( 'Followers', 'buddynext' ),
				'url'               => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'followers' ),
				'priority'          => 20,
				'lightweight_count' => true,
				'count'             => static fn( NavContext $c ): int => (int) buddynext_service( 'follows' )->follower_count( $c->subject_id ),
				'render'            => fn( NavContext $c ) => $this->render_people( $c, 'followers' ),
			),
			array(
				'id'                => 'following',
				'surface'           => 'profile',
				'layer'             => 'primary',
				'parent'            => 'network',
				'label'             => __( 'Following', 'buddynext' ),
				'url'               => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'following' ),
				'priority'          => 30,
				'lightweight_count' => true,
				'count'             => static fn( NavContext $c ): int => (int) buddynext_service( 'follows' )->following_count( $c->subject_id ),
				'render'            => fn( NavContext $c ) => $this->render_people( $c, 'following' ),
			),
		);
	}

	/**
	 * Posts panel — the viewer-gated profile feed (canonically hydrated), with the
	 * owner composer. Only the active panel runs this, so the feed query is paid
	 * only on the Posts tab.
	 *
	 * @param NavContext $c Context.
	 * @return void
	 */
	private function render_posts( NavContext $c ): void {
		$feed  = buddynext_service( 'feed' )->profile_feed( $c->subject_id, $c->viewer_id, null, 10 );
		$posts = is_array( $feed ) && isset( $feed['items'] ) ? (array) $feed['items'] : array();
		buddynext_get_template(
			'parts/profile/posts-panel.php',
			array(
				'kind'         => 'posts',
				'posts'        => $posts,
				'viewer_id'    => $c->viewer_id,
				'is_owner'     => $c->is_self(),
				'display_name' => $this->display_name( $c->subject_id ),
				'current_user' => $c->viewer_id > 0 ? get_userdata( $c->viewer_id ) : null,
			)
		);
	}

	/**
	 * Scheduled panel — owner-only queued future posts.
	 *
	 * @param NavContext $c Context.
	 * @return void
	 */
	private function render_scheduled( NavContext $c ): void {
		if ( ! $c->is_self() ) {
			return;
		}
		buddynext_get_template(
			'parts/profile/posts-panel.php',
			array(
				'kind'         => 'scheduled',
				'posts'        => (array) buddynext_service( 'post_service' )->user_scheduled_posts( $c->subject_id, 20 ),
				'viewer_id'    => $c->viewer_id,
				'is_owner'     => true,
				'display_name' => $this->display_name( $c->subject_id ),
			)
		);
	}

	/**
	 * Replies panel — the member's replies, each linking back to its activity.
	 *
	 * @param NavContext $c Context.
	 * @return void
	 */
	private function render_replies( NavContext $c ): void {
		$replies = array_map(
			static fn( array $r ): object => (object) $r,
			(array) buddynext_service( 'post_service' )->user_replies( $c->subject_id, 20 )
		);
		buddynext_get_template( 'parts/profile/replies-panel.php', array( 'replies' => $replies ) );
	}

	/**
	 * Likes panel — liked posts rendered through the full post-card.
	 *
	 * @param NavContext $c Context.
	 * @return void
	 */
	private function render_likes( NavContext $c ): void {
		buddynext_get_template(
			'parts/profile/posts-panel.php',
			array(
				'kind'         => 'likes',
				'posts'        => (array) buddynext_service( 'post_service' )->user_liked_posts( $c->subject_id, 20 ),
				'viewer_id'    => $c->viewer_id,
				'is_owner'     => $c->is_self(),
				'display_name' => $this->display_name( $c->subject_id ),
			)
		);
	}

	/**
	 * Media panel — the BN-native media surface (gallery + albums), resolved from
	 * the media engine. Privacy is enforced inside the engine query.
	 *
	 * @param NavContext $c Context.
	 * @return void
	 */
	private function render_media( NavContext $c ): void {
		$ids = MediaClient::available()
			? (array) Galleries::user_media_ids( $c->subject_id, $c->viewer_id, 24, 0 )
			: array();
		buddynext_get_template(
			'partials/media-tab.php',
			array(
				'bn_mt_owner_id'       => $c->subject_id,
				'bn_mt_is_owner'       => $c->is_self(),
				'bn_mt_media_ids'      => $ids,
				'bn_mt_albums_enabled' => buddynext_integration_enabled( 'media', 'nav', 'albums' ),
			)
		);
	}

	/**
	 * About panel — the curated about-cards + every other admin-defined field via
	 * the field-type engine. The part self-fetches the viewer-gated field data.
	 *
	 * @param NavContext $c Context.
	 * @return void
	 */
	private function render_about( NavContext $c ): void {
		buddynext_get_template(
			'parts/profile/about-panel.php',
			array(
				'profile_user_id' => $c->subject_id,
				'viewer_id'       => $c->viewer_id,
			)
		);
	}

	/**
	 * People panel (followers / following / connections) — the capped member grid
	 * + the owner-only pending-request inbox for that relation.
	 *
	 * @param NavContext $c        Context.
	 * @param string     $relation followers | following | connections.
	 * @return void
	 */
	private function render_people( NavContext $c, string $relation ): void {
		$uid      = $c->subject_id;
		$is_owner = $c->is_self();
		$follow   = buddynext_service( 'follows' );
		$conn     = buddynext_service( 'connections' );

		$members = array();
		$pending = array();
		if ( 'followers' === $relation ) {
			$members = $this->ids_to_users( array_slice( (array) $follow->followers( $uid ), 0, 60 ) );
			if ( $is_owner ) {
				$pending = $this->ids_to_users( (array) $follow->pending_followers( $uid ) );
			}
		} elseif ( 'following' === $relation ) {
			$members = $this->ids_to_users( array_slice( (array) $follow->following( $uid ), 0, 60 ) );
		} else {
			$members = $this->ids_to_users( (array) $conn->connections( $uid, 60, 0 ) );
			if ( $is_owner ) {
				$pending = $this->ids_to_users( (array) $conn->pending_received( $uid, 60, 0 ) );
			}
		}

		buddynext_get_template(
			'parts/profile/people-panel.php',
			array(
				'relation'     => $relation,
				'members'      => $members,
				'pending'      => $pending,
				'viewer_id'    => $c->viewer_id,
				'is_owner'     => $is_owner,
				'display_name' => $this->display_name( $uid ),
			)
		);
	}

	/**
	 * Map a list of user ids to WP_User objects (dropping any that don't resolve).
	 *
	 * @param array<int,mixed> $ids User ids.
	 * @return \WP_User[]
	 */
	private function ids_to_users( array $ids ): array {
		return array_values(
			array_filter(
				array_map( static fn( $id ) => get_userdata( (int) $id ), $ids )
			)
		);
	}

	/**
	 * A profile's display name (empty string when the user is gone).
	 *
	 * @param int $uid User ID.
	 * @return string
	 */
	private function display_name( int $uid ): string {
		$user = get_userdata( $uid );
		return $user ? (string) $user->display_name : '';
	}

	/**
	 * Whether the profile has any "About" content — gates the About tab so it never
	 * appears empty. Mirrors what the About panel renders: work / education entries,
	 * interests, and any other admin-defined field beyond the hero keys (basic_info
	 * headline/bio/pronouns/location/website) and the social-links group.
	 *
	 * @param NavContext $c Context.
	 * @return bool
	 */
	private function has_about_content( NavContext $c ): bool {
		$profile = buddynext_service( 'profiles' )->get_profile( $c->subject_id, $c->viewer_id );
		if ( ! is_array( $profile ) ) {
			return false;
		}
		$hero = array( 'headline', 'bio', 'pronouns', 'location', 'website' );
		foreach ( (array) ( $profile['groups'] ?? array() ) as $group ) {
			$gkey = (string) ( $group['group_key'] ?? '' );
			if ( '' === $gkey || 'social_links' === $gkey ) {
				continue;
			}
			// Repeater entries (work / education) with any value.
			foreach ( (array) ( $group['entries'] ?? array() ) as $entry ) {
				foreach ( (array) $entry as $field ) {
					if ( is_array( $field ) && '' !== (string) ( $field['value'] ?? '' ) ) {
						return true;
					}
				}
			}
			// Flat fields with a value, skipping the hero keys in basic_info.
			foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$fkey = (string) ( $field['field_key'] ?? '' );
				if ( 'basic_info' === $gkey && in_array( $fkey, $hero, true ) ) {
					continue;
				}
				if ( '' !== (string) ( $field['value'] ?? '' ) ) {
					return true;
				}
			}
		}
		return false;
	}
}

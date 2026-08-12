<?php
/**
 * Per-surface social descriptors for every shareable BuddyNext page.
 *
 * Each hub describes itself here and hands the descriptor to {@see HeadMeta},
 * which owns the rendering and the image contract. Adding a surface means
 * adding a case below — never a second head emitter.
 *
 * Privacy is an input, not an afterthought. A secret or private space, and any
 * surface while the community is private, is marked noindex and describes
 * itself generically: a scraper must never be handed a description or image
 * that the same visitor could not load the page to see.
 *
 * @package BuddyNext\Core
 * @since   1.1.3
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and registers the head descriptor for the current BuddyNext surface.
 */
final class SurfaceMeta {

	/**
	 * Describe the current surface and hand it to HeadMeta.
	 *
	 * Called from PageRouter::dispatch_hub_template() once the hub and its
	 * context are resolved and every access gate has passed, but before
	 * wp_head() runs.
	 *
	 * @param string              $hub     Active hub key.
	 * @param array<string,mixed> $context Hub context from build_hub_context().
	 * @return void
	 */
	public static function register( string $hub, array $context ): void {
		// The single-post permalink has its own richer descriptor (article type,
		// author, timestamps) emitted by Feed\SinglePostMeta.
		if ( 'post' === $hub ) {
			return;
		}

		$descriptor = self::describe( $hub, $context );
		if ( empty( $descriptor ) ) {
			return;
		}

		// A private community is login-only: never feed indexes or scrapers a
		// description or image from behind that gate.
		if ( PrivateCommunity::is_enabled() ) {
			$descriptor['noindex']     = true;
			$descriptor['description'] = '';
			// Drop any content imagery; HeadMeta supplies the site fallback.
			$descriptor['image'] = '';
		}

		HeadMeta::emit( $descriptor );
	}

	/**
	 * Build the descriptor for a hub.
	 *
	 * @param string              $hub     Active hub key.
	 * @param array<string,mixed> $context Hub context.
	 * @return array<string,mixed> Descriptor, or [] when the surface is not describable.
	 */
	private static function describe( string $hub, array $context ): array {
		switch ( $hub ) {
			case 'spaces':
				$space_id = (int) ( $context['space_id'] ?? 0 );
				return $space_id > 0
					? self::describe_space( $space_id )
					: self::describe_directory(
						__( 'Spaces', 'buddynext' ),
						PageRouter::hub_url( 'buddynext_slug_spaces', 'buddynext_page_spaces' )
					);

			case 'people':
				$user_id = (int) ( $context['user_id'] ?? 0 );
				return $user_id > 0
					? self::describe_member( $user_id )
					: self::describe_directory(
						__( 'Members', 'buddynext' ),
						PageRouter::hub_url( 'buddynext_slug_people', 'buddynext_page_people' )
					);

			case 'feed':
				return self::describe_feed();

			// Personal, login-only surfaces: describable only as noindex stubs so
			// a stray share or crawl cannot surface someone's inbox.
			case 'messages':
			case 'notifications':
				return array(
					'url'     => home_url( add_query_arg( array() ) ),
					'title'   => self::community_name(),
					'noindex' => true,
				);
		}

		return array();
	}

	/**
	 * Describe the activity hub and its sub-surfaces.
	 *
	 * The feed hub is several distinct pages behind one hub key — the explore
	 * deck, a hashtag, a search — and they are shared as different things. A
	 * bare community name for all of them makes both the browser tab and the
	 * shared card useless for telling them apart.
	 *
	 * @return array<string,mixed>
	 */
	private static function describe_feed(): array {
		$action  = (string) get_query_var( 'bn_activity_action', '' );
		$hashtag = trim( (string) get_query_var( 'bn_hashtag', '' ) );

		if ( 'hashtag' === $action && '' !== $hashtag ) {
			return self::describe_directory(
				/* translators: %s: hashtag without the leading hash. */
				sprintf( __( '#%s', 'buddynext' ), $hashtag ),
				PageRouter::activity_url() . 'hashtag/' . rawurlencode( $hashtag ) . '/'
			);
		}

		if ( 'search' === $action ) {
			// A search results page is per-visitor and not a shareable entity.
			return array(
				'url'     => PageRouter::activity_url() . 'search/',
				'title'   => __( 'Search', 'buddynext' ),
				'noindex' => true,
			);
		}

		$label = 'explore' === $action
			? __( 'Explore', 'buddynext' )
			: __( 'Activity', 'buddynext' );

		$url = 'explore' === $action
			? PageRouter::activity_url() . 'explore/'
			: PageRouter::activity_url();

		return self::describe_directory( $label, $url );
	}

	/**
	 * Describe a single space.
	 *
	 * @param int $space_id Space ID.
	 * @return array<string,mixed>
	 */
	private static function describe_space( int $space_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$space = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT name, description, type, avatar_url, cover_image_url
				FROM {$wpdb->prefix}bn_spaces WHERE id = %d LIMIT 1",
				$space_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $space ) {
			return array();
		}

		$name = (string) $space['name'];

		// Private and secret spaces exist, and their NAME is already on the
		// membership screens, but their description, imagery and index presence
		// are not public property.
		$is_restricted = in_array( (string) $space['type'], array( 'private', 'secret' ), true );

		if ( $is_restricted ) {
			/* translators: %s: space name. */
			$description = sprintf( __( '%s is a private space.', 'buddynext' ), $name );
		} else {
			$description = (string) $space['description'];
		}

		$image = $is_restricted
			? ''
			: HeadMeta::first_usable_image(
				array(
					(string) $space['cover_image_url'],
					(string) $space['avatar_url'],
				)
			);

		return array(
			'url'         => PageRouter::space_url( $space_id ),
			'title'       => $name,
			'type'        => 'website',
			'description' => $description,
			'image'       => $image,
			'noindex'     => $is_restricted,
		);
	}

	/**
	 * Describe a single member profile.
	 *
	 * Deliberately does NOT pull profile-field values (bio, headline, location):
	 * those carry per-field visibility that only a viewer-aware read can honour,
	 * and a scraper is the least authenticated viewer there is. The card leads
	 * with the member's name and the community, which is what a shared profile
	 * link is for. Wiring a visibility-filtered headline into the description is
	 * tracked as a follow-up.
	 *
	 * @param int $user_id Member ID.
	 * @return array<string,mixed>
	 */
	private static function describe_member( int $user_id ): array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array();
		}

		$name = (string) $user->display_name;

		return array(
			'url'         => PageRouter::profile_url( $user_id ),
			'title'       => $name,
			'type'        => 'profile',
			/* translators: 1: member display name, 2: community name. */
			'description' => sprintf( __( '%1$s on %2$s.', 'buddynext' ), $name, self::community_name() ),
			// A generated letter-avatar is a data: URI and is dropped by the
			// image contract, falling through to the site image.
			'image'       => HeadMeta::first_usable_image(
				array(
					(string) get_user_meta( $user_id, 'bn_avatar', true ),
					(string) get_avatar_url( $user_id, array( 'size' => 512 ) ),
				)
			),
		);
	}

	/**
	 * Describe a directory / hub landing surface.
	 *
	 * @param string $label Human label for the surface.
	 * @param string $url   Canonical URL.
	 * @return array<string,mixed>
	 */
	private static function describe_directory( string $label, string $url ): array {
		return array(
			'url'         => $url,
			// The BARE label, never "Label - Community". WordPress appends the
			// site name to the document title itself, so composing it here
			// produced "Members - BuddyNext - BuddyNext" in the browser tab and
			// in search results. og:site_name already carries the community name
			// for the social card, so the bare label is correct on both surfaces.
			'title'       => $label,
			'description' => (string) get_option( 'buddynext_description', '' ),
			'type'        => 'website',
		);
	}

	/**
	 * The community's display name (delegated - HeadMeta owns the source).
	 *
	 * @return string
	 */
	private static function community_name(): string {
		return HeadMeta::community_name();
	}
}

<?php
/**
 * Shared feed-activity helper for integrations.
 *
 * One consistent way for any integration bridge (core or Pro) to publish a
 * "member created partner content" activity to the BuddyNext feed (engagement),
 * and to remove it when the content goes away. Goes through PostService — no raw
 * SQL, one link-card style for every integration, idempotent per partner page.
 *
 * Lives in Free so both Free core bridges (e.g. Jetonomy) and Pro bridges
 * (e.g. Career Board) use the same helper without duplicating the logic.
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

/**
 * Publish / remove integration engagement activities.
 */
class IntegrationActivity {

	/**
	 * Whether a bridge-driven (system) publish is currently in flight.
	 *
	 * Read by SpacePostGuard to skip the per-space `who_can_post` gate. That gate
	 * governs a MEMBER composing into a space; an integration activity is a mirror
	 * of content the partner plugin already created and already authorized, whose
	 * author frequently is not a member of the linked BuddyNext space at all.
	 *
	 * Deliberately a system flag rather than a key in the post data array: a data
	 * key could be smuggled in through a create path and would turn the skip into
	 * a who_can_post bypass. Nothing a request can supply reaches this.
	 *
	 * @var bool
	 */
	private static bool $system_publish = false;

	/**
	 * Whether the current save is a bridge-driven integration publish.
	 *
	 * @return bool
	 */
	public static function is_system_publish(): bool {
		return self::$system_publish;
	}

	/**
	 * Publish a link-card activity for content a member just created.
	 *
	 * Rendered as a standard feed link card pointing at the partner's own page
	 * (we link OUT — we never embed the partner UI). `link_meta` is supplied so
	 * PostService does not make an OG-fetch HTTP call to the partner.
	 *
	 * @param int    $member_id  The member who created the content.
	 * @param string $content    Feed text, e.g. "started a discussion".
	 * @param string $link_url   The partner page the card links to.
	 * @param string $link_title Title shown on the card (the content's title).
	 * @param string $type       Post type to record. Defaults to 'link'. Pass a
	 *                           specific type (e.g. 'discussion', 'job')
	 *                           so discovery surfaces can classify + filter the
	 *                           card by what it represents instead of a generic
	 *                           link. Must be a PostService::ALLOWED_TYPES value.
	 * @param string $excerpt    Optional short excerpt of the partner content,
	 *                           stored in link_meta['description'] so the card can
	 *                           show a title + preview instead of just a verb.
	 * @param int    $space_id   The BuddyNext space the partner content belongs to,
	 *                           when it belongs to one. This is what makes the
	 *                           per-space "Share activity to the main feed" toggle
	 *                           real: FeedService excludes an opted-out space by
	 *                           `space_id`, and its clause deliberately lets a NULL
	 *                           space through, so a card published without one can
	 *                           never be suppressed — and never appears in the
	 *                           space's own feed either. Pass 0 for content that
	 *                           genuinely has no space (a badge, a resume).
	 * @return int|\WP_Error Post id (0 when an identical card already exists), or WP_Error.
	 */
	public static function publish( int $member_id, string $content, string $link_url, string $link_title = '', string $type = 'link', string $excerpt = '', int $space_id = 0 ) {
		if ( $member_id <= 0 || '' === $link_url ) {
			return new \WP_Error( 'invalid_activity', 'member id and link url are required' );
		}

		$type    = '' !== $type ? $type : 'link';
		$service = new PostService();

		// Idempotent: one activity card per partner page, even if the partner
		// hook fires more than once. Match on the same type the card is stored as.
		if ( $service->exists_by_link( $type, $link_url ) ) {
			return 0;
		}

		self::$system_publish = true;

		try {
			return $service->create(
				$member_id,
				array(
					'type'      => $type,
					'content'   => $content,
					'space_id'  => $space_id > 0 ? $space_id : null,
					// Integration activities link OUT to a public partner page (a
					// public Jetonomy discussion, a job posting, etc.), so they are
					// inherently public. Set it explicitly rather than inheriting the
					// site's default-post-privacy option, which may be blank.
					'privacy'   => 'public',
					'link_url'  => $link_url,
					'link_meta' => array(
						'title'       => $link_title,
						'description' => $excerpt,
						'image'       => '',
						'url'         => $link_url,
					),
				)
			);
		} finally {
			self::$system_publish = false;
		}
	}

	/**
	 * The permalink a post HAS, or HAD while it was published.
	 *
	 * Forces `publish` status on a probe copy and strips WordPress's `__trashed` slug
	 * suffix, so the URL is identical to the one stored on the activity card when the
	 * content first went public — whatever the post's current (draft / trashed) state.
	 *
	 * That identity is the whole point: remove() matches an activity card by its
	 * link_url, so a bridge tearing down the card for a trashed post MUST be able to
	 * reconstruct the URL exactly as it was written. Ask WordPress for the permalink of
	 * a trashed post and you get the `__trashed` slug, which matches nothing, and the
	 * activity card is orphaned in the feed forever.
	 *
	 * Lives here because this is the class every bridge already goes through to publish
	 * and remove a card — the URL rule belongs next to the thing it is a key for. It was
	 * previously copy-pasted, byte for byte, into two Pro bridges.
	 *
	 * @param \WP_Post $post The partner post (listing, job, …).
	 * @return string
	 */
	public static function published_permalink( \WP_Post $post ): string {
		if ( 'publish' === $post->post_status ) {
			return (string) get_permalink( $post );
		}

		$probe              = clone $post;
		$probe->post_status = 'publish';
		$probe->post_name   = (string) preg_replace( '/__trashed$/', '', (string) $probe->post_name );

		return (string) get_permalink( $probe );
	}

	/**
	 * Remove the activity card for a partner page (e.g. when the content is deleted).
	 *
	 * @param string $link_url The partner page the card linked to.
	 * @param string $type     Post type the card was stored as. Defaults to 'link';
	 *                         pass the same type used at publish() time.
	 * @return int Rows removed.
	 */
	public static function remove( string $link_url, string $type = 'link' ): int {
		if ( '' === $link_url ) {
			return 0;
		}
		return ( new PostService() )->delete_by_link( '' !== $type ? $type : 'link', $link_url );
	}
}

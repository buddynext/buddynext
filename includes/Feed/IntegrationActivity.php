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
	 * @return int|\WP_Error Post id (0 when an identical card already exists), or WP_Error.
	 */
	public static function publish( int $member_id, string $content, string $link_url, string $link_title = '', string $type = 'link', string $excerpt = '' ) {
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

		return $service->create(
			$member_id,
			array(
				'type'      => $type,
				'content'   => $content,
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

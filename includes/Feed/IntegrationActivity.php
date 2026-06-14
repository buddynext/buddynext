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
	 * @return int|\WP_Error Post id (0 when an identical card already exists), or WP_Error.
	 */
	public static function publish( int $member_id, string $content, string $link_url, string $link_title = '' ) {
		if ( $member_id <= 0 || '' === $link_url ) {
			return new \WP_Error( 'invalid_activity', 'member id and link url are required' );
		}

		$service = new PostService();

		// Idempotent: one activity card per partner page, even if the partner
		// hook fires more than once.
		if ( $service->exists_by_link( 'link', $link_url ) ) {
			return 0;
		}

		return $service->create(
			$member_id,
			array(
				'type'      => 'link',
				'content'   => $content,
				'link_url'  => $link_url,
				'link_meta' => array(
					'title'       => $link_title,
					'description' => '',
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
	 * @return int Rows removed.
	 */
	public static function remove( string $link_url ): int {
		if ( '' === $link_url ) {
			return 0;
		}
		return ( new PostService() )->delete_by_link( 'link', $link_url );
	}
}

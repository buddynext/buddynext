<?php
/**
 * The words and the one button a visitor sees on an event they may not open yet.
 *
 * An event at the Space members level is closed to people outside its home Space. Instead of "not found",
 * Eventonomy shows a preview (title, date, city, cover) and asks the host plugin for the words and the link.
 * This class is that answer, from the Space's own rules:
 *  - a guest: join the Space, or log in, both coming back to the event;
 *  - a signed-in person on an open Space: join it, then come back to the event;
 *  - a signed-in person on a private Space: ask to join; once asked, a waiting note;
 *  - a secret Space (or none): no preview, so the event stays a plain "not found".
 * A Space that needs a plan is left to BuddyNext Pro, which has the plan wording and price.
 *
 * @package BuddyNext\Bridges
 * @since   1.3.0
 */

declare(strict_types=1);

namespace BuddyNext\Bridges;

use BuddyNext\Core\PageRouter;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use BuddyNext\Spaces\SpaceTypeRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Preview words for a blocked viewer.
 */
class EventonomyTeaser {

	/**
	 * First Eventonomy hook-contract version whose preview takes a heading, a disabled button and a second link.
	 */
	private const MIN_CONTRACT = 4;

	/**
	 * Register the answer when the installed Eventonomy asks for it.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! defined( 'EVENTONOMY_HOOK_CONTRACT' ) || (int) EVENTONOMY_HOOK_CONTRACT < self::MIN_CONTRACT ) {
			return;
		}
		add_filter( 'evnm_event_teaser', array( $this, 'teaser' ), 10, 4 );
	}

	/**
	 * The preview for one viewer, or what Eventonomy already had.
	 *
	 * @param mixed $teaser  Eventonomy's default (null keeps the "not found" page).
	 * @param mixed $event   Event row.
	 * @param mixed $user_id Viewer (0 = guest).
	 * @param mixed $level   The event's level.
	 * @return mixed
	 */
	public function teaser( $teaser, $event, $user_id, $level = '' ) {
		if ( EventonomyAccess::SPACE_LEVEL !== (string) $level || ! is_array( $event ) ) {
			return $teaser;
		}
		$user_id  = (int) $user_id;
		$space_id = (int) ( $event['space_id'] ?? 0 );
		$space    = $space_id > 0 ? ( new SpaceService() )->get( $space_id ) : null;
		if ( ! is_array( $space ) ) {
			return null;
		}

		$config = SpaceTypeRegistry::instance()->get( (string) ( $space['type'] ?? 'open' ) );
		if ( 'secret' === (string) ( $config['visibility'] ?? '' ) ) {
			return null;
		}

		$link = (string) apply_filters( 'evnm_event_permalink', '', (int) ( $event['id'] ?? 0 ), $event );
		$name = (string) ( $space['name'] ?? '' );
		$base = array(
			/* translators: %s: Space name. */
			'heading' => sprintf( __( 'This event is for %s members', 'buddynext' ), $name ),
		);

		if ( $user_id <= 0 ) {
			return $base + array(
				/* translators: %s: Space name. */
				'message'         => sprintf( __( 'Join %s to see the full details and take a place.', 'buddynext' ), $name ),
				/* translators: %s: Space name. */
				'cta_label'       => sprintf( __( 'Join %s', 'buddynext' ), $name ),
				'cta_url'         => PageRouter::signup_url( $link ),
				'secondary_label' => __( 'Log in', 'buddynext' ),
				'secondary_url'   => PageRouter::login_url( $link ),
			);
		}

		// A Space that needs a plan has its words and price in BuddyNext Pro, which answers after this.
		if ( '' !== (string) ( $space['required_ability'] ?? '' ) ) {
			return $teaser;
		}

		$space_url = add_query_arg(
			array(
				'return_to'  => rawurlencode( $link ),
				'return_for' => 'event',
			),
			PageRouter::space_url( $space_id )
		);

		$private = 'public' !== (string) ( $config['visibility'] ?? 'public' );

		if ( $private && 'pending' === ( new SpaceMemberService() )->get_status( $space_id, $user_id ) ) {
			return array(
				'heading'         => __( 'Your request is waiting for approval', 'buddynext' ),
				'message'         => __( 'An organiser will review your request to join. You will get a notice when it is answered.', 'buddynext' ),
				'cta_label'       => __( 'Request sent', 'buddynext' ),
				'cta_disabled'    => true,
				'secondary_label' => __( 'Cancel request', 'buddynext' ),
				'secondary_url'   => $space_url,
			);
		}

		if ( $private ) {
			return $base + array(
				/* translators: %s: Space name. */
				'message'   => sprintf( __( '%s is a private group. Ask to join and an organiser will review your request.', 'buddynext' ), $name ),
				/* translators: %s: Space name. */
				'cta_label' => sprintf( __( 'Request to join %s', 'buddynext' ), $name ),
				'cta_url'   => $space_url,
			);
		}

		return $base + array(
			/* translators: %s: Space name. */
			'message'   => sprintf( __( 'Join %s to see the full details and take a place.', 'buddynext' ), $name ),
			/* translators: %s: Space name. */
			'cta_label' => sprintf( __( 'Join %s', 'buddynext' ), $name ),
			'cta_url'   => $space_url,
		);
	}
}

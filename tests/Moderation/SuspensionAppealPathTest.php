<?php
/**
 * A suspended member is always told where to appeal.
 *
 * The appeal page (/me/account-status/) was fully built — suspension reason,
 * strike history, a working inline appeal form — and nothing in the UI linked to
 * it. A suspended member could only reach it by already knowing the URL, which
 * defeats the point of having built it: submit_appeal() needs a suspension id
 * they had no way to obtain, so the appeal flow was unreachable in practice.
 *
 * The two places a member actually hits the wall each returned a bare
 * translated sentence with `array( 'status' => 403 )` and nothing else. In the
 * comment box that produced a Retry button for a refusal that can never succeed
 * on retry.
 *
 * The URL travels in the error DATA rather than inside the sentence so the
 * client can render a real link — and the native app can route to its own
 * screen — instead of parsing prose for a URL.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Core\PageRouter;
use BuddyNext\Feed\PostService;
use BuddyNext\Moderation\InteractionGuard;
use BuddyNext\Moderation\ModerationService;
use WP_Error;

/**
 * Both suspension walls carry the way out.
 *
 * @covers \BuddyNext\Moderation\ModerationService::suspension_error
 */
class SuspensionAppealPathTest extends \WP_UnitTestCase {

	/**
	 * Moderation service.
	 *
	 * @var ModerationService
	 */
	private $moderation;

	/**
	 * The suspended member.
	 *
	 * @var int
	 */
	private $member = 0;

	/**
	 * The moderator issuing the suspension.
	 *
	 * @var int
	 */
	private $admin = 0;

	/**
	 * A post for the member to try to interact with.
	 *
	 * @var int
	 */
	private $post = 0;

	/**
	 * Boot the schema, the users, and suspend the member.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->moderation = new ModerationService();
		$this->admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->member     = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$author     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->post = (int) ( new PostService() )->create( $author, array( 'content' => 'A post to react to.' ) );

		$this->moderation->suspend_user( $this->member, $this->admin, 'Spam', array( 'duration_days' => 7 ) );
	}

	/**
	 * The content-creation wall (PostService::create).
	 *
	 * @return void
	 */
	public function test_the_post_wall_carries_the_appeal_url(): void {
		$result = ( new PostService() )->create( $this->member, array( 'content' => 'Blocked post' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$data = (array) $result->get_error_data();

		$this->assertSame( 403, $data['status'] );
		$this->assertSame(
			PageRouter::account_status_url(),
			$data['appeal_url'] ?? '',
			'A suspended member was refused with no way to reach the appeal page.'
		);
	}

	/**
	 * The interaction wall (InteractionGuard), which backs comments and both
	 * reaction paths.
	 *
	 * @return void
	 */
	public function test_the_interaction_wall_carries_the_appeal_url(): void {
		$result = InteractionGuard::check( $this->member, 'post', $this->post );

		$this->assertInstanceOf( WP_Error::class, $result );
		$data = (array) $result->get_error_data();

		$this->assertSame( 403, $data['status'] );
		$this->assertSame( PageRouter::account_status_url(), $data['appeal_url'] ?? '' );
	}

	/**
	 * Both walls produce the SAME url. They previously held two independent
	 * copies of this decision, which is how they drifted apart in the first
	 * place, and a future third wall is meant to reuse the builder rather than
	 * write a third.
	 *
	 * @return void
	 */
	public function test_both_walls_agree_on_the_destination(): void {
		$post        = ( new PostService() )->create( $this->member, array( 'content' => 'Blocked post' ) );
		$interaction = InteractionGuard::check( $this->member, 'post', $this->post );

		$this->assertSame(
			( (array) $post->get_error_data() )['appeal_url'],
			( (array) $interaction->get_error_data() )['appeal_url']
		);
	}

	/**
	 * The URL is DATA, not prose. A client that renders the message as text must
	 * not end up printing a raw URL mid-sentence, and a client building a link
	 * must not have to parse one out of a translated string.
	 *
	 * @return void
	 */
	public function test_the_url_is_not_baked_into_the_message(): void {
		$result = ( new PostService() )->create( $this->member, array( 'content' => 'Blocked post' ) );

		$this->assertStringNotContainsString( 'http', $result->get_error_message() );
		$this->assertStringNotContainsString( 'account-status', $result->get_error_message() );
	}

	/**
	 * Refusals that are NOT suspensions must not carry an appeal link — there is
	 * nothing to appeal, and offering one would send the member to a page that
	 * says their account is fine. This is the assertion that stops the URL being
	 * added to the generic error path later "for consistency".
	 *
	 * @return void
	 */
	public function test_a_non_suspension_refusal_carries_no_appeal_url(): void {
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// A block, not a suspension: refused for a different reason entirely.
		$author = (int) ( new PostService() )->get( $this->post )['user_id'];
		buddynext_service( 'blocks' )->block( $author, $other );

		$result = InteractionGuard::check( $other, 'post', $this->post );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertArrayNotHasKey(
			'appeal_url',
			(array) $result->get_error_data(),
			'A non-suspension refusal offered an appeal path.'
		);
	}

	/**
	 * Lifting the suspension removes the wall entirely — the member posts again
	 * and gets no error to carry a link.
	 *
	 * @return void
	 */
	public function test_an_unsuspended_member_is_not_walled(): void {
		$this->moderation->unsuspend_user( $this->member, $this->admin );

		$result = ( new PostService() )->create( $this->member, array( 'content' => 'Allowed again' ) );

		$this->assertNotWPError( $result, 'A member kept hitting the suspension wall after being unsuspended.' );
	}

	/**
	 * The REACTION path carries it too.
	 *
	 * This is the one QA bounced the first fix on, and they were right: reactions
	 * go through the same InteractionGuard, so the server always answered
	 * correctly — but the client discarded the body and showed a generic
	 * "Could not update your reaction. Try again." on comments and NOTHING at all
	 * on posts. Reactions are the highest-frequency interaction in the product,
	 * so it is the most likely place a suspended member first hits the wall.
	 *
	 * Asserted at the guard, which is what the reaction controller calls for both
	 * object types.
	 *
	 * @return void
	 */
	public function test_the_reaction_path_carries_the_appeal_url(): void {
		foreach ( array( 'post', 'comment' ) as $object_type ) {
			$result = InteractionGuard::check( $this->member, $object_type, $this->post );

			$this->assertInstanceOf( WP_Error::class, $result, $object_type . ' reactions were not refused.' );
			$this->assertSame(
				PageRouter::account_status_url(),
				( (array) $result->get_error_data() )['appeal_url'] ?? '',
				'A suspended member reacting to a ' . $object_type . ' gets no way to appeal.'
			);
		}
	}
}

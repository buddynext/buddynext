<?php
/**
 * A pinned comment belongs at the top of its thread, always.
 *
 * `CommentService::list()` lifted the pinned comment to position 0 only when it
 * was NOT already on the fetched page. When it was — which is every thread with
 * fewer than one page of comments, so very nearly every thread — it flagged the
 * item `pinned` and left it exactly where chronology had put it.
 *
 * So pinning the newest of three comments returned [3, 4, 5(pinned)] and not
 * [5(pinned), 3, 4]. The write succeeded, the option was stored, the badge
 * appeared, and the one thing the feature exists to do did not happen. On a busy
 * thread the pin worked and on a quiet one it did not, which is the reverse of
 * what anyone would guess.
 *
 * The absent-from-page branch was written first and is the harder one — it
 * fetches the comment separately and has to run it back through the block
 * filter. The already-present branch reads like the easy case, and the reorder
 * was simply left out of it.
 *
 * @package BuddyNext\Tests\Comments
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Comments;

/**
 * Pinned-comment ordering.
 *
 * @covers \BuddyNext\Comments\CommentService::list
 */
class PinnedCommentSitsOnTopTest extends \WP_UnitTestCase {

	/**
	 * Moderator doing the pinning.
	 *
	 * @var int
	 */
	private int $moderator = 0;

	/**
	 * Post the thread hangs on.
	 *
	 * @var int
	 */
	private int $post_id = 0;

	/**
	 * Top-level comment ids, oldest first.
	 *
	 * @var int[]
	 */
	private array $roots = array();

	/**
	 * A post carrying three top-level comments.
	 *
	 * @return void
	 */
	public function set_up(): void {
		global $wpdb;

		parent::set_up();

		$this->moderator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->moderator );

		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'    => $this->moderator,
				'content'    => 'A post with a short comment thread',
				'type'       => 'text',
				'privacy'    => 'public',
				'status'     => 'published',
				'space_id'   => 0,
				'created_at' => current_time( 'mysql', true ),
			)
		);
		$this->post_id = (int) $wpdb->insert_id;

		$comments = buddynext_service( 'comments' );
		foreach ( array( 'First', 'Second', 'Third' ) as $body ) {
			$created       = $comments->create( $this->moderator, 'post', $this->post_id, $body );
			$this->roots[] = is_array( $created ) ? (int) ( $created['id'] ?? 0 ) : (int) $created;
		}
	}

	/**
	 * Pin a comment, and refuse to continue if the pin did not take.
	 *
	 * Guards the guard. The first draft of this file called `pin( $moderator, $id )`
	 * — the ( actor, target ) order `create()` takes — where the real signature is
	 * ( comment_id, user_id ). Every ordering assertion then failed against a thread
	 * that had never been pinned at all, which looks exactly like the bug under
	 * test. Asserting the pin succeeded is what tells those two apart.
	 *
	 * @param int $comment_id Comment to pin.
	 * @return void
	 */
	private function pin( int $comment_id ): void {
		$this->assertTrue(
			buddynext_service( 'comments' )->pin( $comment_id, $this->moderator ),
			'pin() refused, so every assertion below would be measuring an unpinned thread.'
		);
	}

	/**
	 * Top-level comment ids as the thread currently lists them.
	 *
	 * @param int $per_page Page size, so the same assertion can be made with the
	 *                      pinned comment on and off the fetched page.
	 * @return int[]
	 */
	private function listed_ids( int $per_page = 50 ): array {
		$listed = buddynext_service( 'comments' )->list(
			'post',
			$this->post_id,
			array(
				'viewer_id' => $this->moderator,
				'per_page'  => $per_page,
			)
		);

		return array_map(
			static fn( array $c ): int => (int) $c['id'],
			(array) ( $listed['items'] ?? $listed )
		);
	}

	/**
	 * The reported case: a short thread, where the pinned comment is already on the page.
	 *
	 * @return void
	 */
	public function test_a_pinned_comment_already_on_the_page_is_lifted_to_the_top(): void {
		$newest = $this->roots[2];

		$this->assertNotSame( $newest, $this->listed_ids()[0], 'Precondition: the comment to pin must NOT already be first, or this proves nothing.' );

		$this->pin( $newest );

		$this->assertSame(
			$newest,
			$this->listed_ids()[0],
			'The pinned comment kept its chronological slot, so pinning it did nothing a reader can see.'
		);
	}

	/**
	 * Lifting it out must not drop it, duplicate it, or disturb the rest.
	 *
	 * The obvious wrong fix — prepend without removing — renders the pinned
	 * comment twice. That reads as a fix in a screenshot of the top of the thread.
	 *
	 * @return void
	 */
	public function test_the_rest_of_the_thread_keeps_its_order_and_nothing_is_duplicated(): void {
		$this->pin( $this->roots[2] );

		$ids = $this->listed_ids();

		$this->assertSame( array( $this->roots[2], $this->roots[0], $this->roots[1] ), $ids, 'Expected the pinned comment first and the others still oldest-first behind it.' );
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ), 'The pinned comment was prepended without being removed from its old slot.' );
	}

	/**
	 * Pinning the comment that is ALREADY first changes nothing.
	 *
	 * A reorder that special-cases index 0 badly can drop it.
	 *
	 * @return void
	 */
	public function test_pinning_the_first_comment_is_a_no_op(): void {
		$this->pin( $this->roots[0] );

		$this->assertSame( array( $this->roots[0], $this->roots[1], $this->roots[2] ), $this->listed_ids() );
	}

	/**
	 * The branch that always worked keeps working: pinned comment off the page.
	 *
	 * With per_page = 1 the newest comment falls outside the fetched page, so
	 * `list()` takes the fetch-and-prepend path instead. Both branches have to end
	 * at the same place, which is the whole point of the fix.
	 *
	 * @return void
	 */
	public function test_a_pinned_comment_off_the_page_is_still_prepended(): void {
		$this->pin( $this->roots[2] );

		$ids = $this->listed_ids( 1 );

		$this->assertSame( $this->roots[2], $ids[0], 'The off-page branch stopped prepending the pinned comment.' );
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ) );
	}

	/**
	 * The item still carries its `pinned` flag, whichever branch produced it.
	 *
	 * The badge and the position are two different things and the bug had exactly
	 * one of them; a fix that trades one for the other is no fix.
	 *
	 * @return void
	 */
	public function test_the_lifted_comment_is_still_flagged_pinned(): void {
		$this->pin( $this->roots[2] );

		$listed = buddynext_service( 'comments' )->list(
			'post',
			$this->post_id,
			array(
				'viewer_id' => $this->moderator,
				'per_page'  => 50,
			)
		);

		$first = ( (array) ( $listed['items'] ?? $listed ) )[0];

		$this->assertSame( $this->roots[2], (int) $first['id'] );
		$this->assertTrue( ! empty( $first['pinned'] ), 'The lifted comment lost the flag the badge renders from.' );
	}

	/**
	 * A reply never advertises a Pin button, because pin() would refuse it.
	 *
	 * `can_pin` was resolved once per OBJECT and copied onto every comment in the
	 * thread, replies included — while `pin()` rejects anything with a parent. So a
	 * moderator saw a Pin button on every reply and every one of them was a
	 * guaranteed 403. "If it renders, it is real": a control that cannot succeed
	 * must not be drawn.
	 *
	 * @return void
	 */
	public function test_a_reply_does_not_advertise_a_pin_button_it_cannot_use(): void {
		$comments = buddynext_service( 'comments' );
		$created  = $comments->create( $this->moderator, 'post', $this->post_id, 'A reply', $this->roots[0] );
		$reply_id = is_array( $created ) ? (int) ( $created['id'] ?? 0 ) : (int) $created;

		$this->assertFalse(
			$comments->pin( $reply_id, $this->moderator ),
			'Precondition: pin() must refuse a reply, or there is nothing to hide the button for.'
		);

		$request = new \WP_REST_Request( 'GET', '/buddynext/v1/comments' );
		$request->set_param( 'object_type', 'post' );
		$request->set_param( 'object_id', $this->post_id );
		$request->set_param( 'per_page', 50 );

		wp_set_current_user( $this->moderator );
		$data = ( new \BuddyNext\Comments\CommentController() )->list_comments( $request )->get_data();

		$seen = array();
		$walk = static function ( array $list ) use ( &$walk, &$seen ): void {
			foreach ( $list as $item ) {
				$seen[ (int) $item['id'] ] = array(
					'parent'  => (int) ( $item['parent_id'] ?? 0 ),
					'can_pin' => (bool) ( $item['can_pin'] ?? false ),
				);
				if ( ! empty( $item['replies'] ) ) {
					$walk( (array) $item['replies'] );
				}
			}
		};
		$walk( (array) ( $data['items'] ?? $data ) );

		$this->assertArrayHasKey( $reply_id, $seen, 'The reply should be somewhere in the returned tree.' );
		$this->assertFalse( $seen[ $reply_id ]['can_pin'], 'A reply advertised a Pin button that always 403s.' );
		$this->assertTrue(
			$seen[ $this->roots[0] ]['can_pin'],
			'Guard the guard: the top-level comment must STILL offer Pin, or this passes by disabling the feature.'
		);
	}
}

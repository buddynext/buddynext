<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The streak summary must be busted when the member does the thing it counts.
 *
 * WHY THIS EXISTS (cache audit, card 10086769257)
 *
 * StreakService caches `streak-summary:{uid}` for 300s with a `wp_cache_get` and a
 * `wp_cache_set` and ZERO `wp_cache_delete` and ZERO hooks. The member posts, extends their
 * streak, refreshes — and sees the OLD number for up to five minutes, on a surface whose
 * entire job is immediate feedback.
 *
 * Its own TTL comment says the quiet part out loud: "a short TTL keeps the strip honest as
 * the user is active mid-session." It does not. That is caching by hope.
 *
 * THE TRAP THIS TEST FILE EXISTS TO PIN
 *
 * The summary is built from bn_posts UNION bn_comments UNION bn_reactions, so the bust must
 * hook those lifecycle actions. But the hook SIGNATURES documented in CLAUDE.md were WRONG
 * for exactly those hooks (fixed in 64ed9138). Wiring from the old doc would have read
 * `$object_id` as a user id:
 *
 *   buddynext_comment_created  — old doc said $user_id was arg 3. It is arg 4; arg 3 is $object_id.
 *   buddynext_reaction_removed — old doc said $user_id was arg 2. It is arg 3; arg 2 is $object_id.
 *
 * So a listener wired from the doc would have busted the cache of *whichever member happens to
 * have the user id matching that post's id* — a random third party — while leaving the actual
 * author's stale summary in place. `test_busting_is_scoped_to_the_acting_member` pins exactly
 * that. It is the reason this file is worth more than a one-line fix.
 *
 * These tests FAIL before the listener exists. Do not weaken them.
 *
 * @package BuddyNext\Tests\Engagement
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Engagement;

use BuddyNext\Comments\CommentService;
use BuddyNext\Engagement\StreakService;
use BuddyNext\Feed\PostService;
use BuddyNext\Reactions\ReactionService;
use WP_UnitTestCase;

/**
 * Streak cache invalidation.
 */
class StreakInvalidationTest extends WP_UnitTestCase {

	/**
	 * The cache group StreakService writes into.
	 *
	 * NOTE: this group is SHARED with WidgetCache::GROUP_USER, so the bust must be
	 * delete-by-key. A group flush here would also destroy the widget cache.
	 *
	 * @var string
	 */
	private const GROUP = 'buddynext_user_meta';

	/**
	 * Service under test.
	 *
	 * @var StreakService
	 */
	private StreakService $streaks;

	/**
	 * The acting member.
	 *
	 * @var int
	 */
	private int $author;

	/**
	 * An uninvolved bystander, used to prove we bust the RIGHT member.
	 *
	 * @var int
	 */
	private int $bystander;

	/**
	 * Fresh services + two members.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->streaks   = new StreakService();
		$this->author    = (int) $this->factory->user->create();
		$this->bystander = (int) $this->factory->user->create();

		wp_cache_flush();
	}

	/**
	 * Prime a member's summary into the cache, and ASSERT the prime actually took.
	 *
	 * Without this assertion the whole suite is worthless: if nothing was ever cached, a
	 * later "the cache is empty" assertion passes for the wrong reason.
	 *
	 * @param int $uid Member.
	 * @return void
	 */
	private function prime( int $uid ): void {
		$this->streaks->summary( $uid );

		$found = false;
		wp_cache_get( 'streak-summary:' . $uid, self::GROUP, false, $found );
		$this->assertTrue(
			$found,
			"the summary for member {$uid} must actually BE cached before we test that a write busts it - "
			. 'otherwise this test passes even with no cache at all'
		);
	}

	/**
	 * Is a member's summary currently cached?
	 *
	 * @param int $uid Member.
	 * @return bool
	 */
	private function is_cached( int $uid ): bool {
		$found = false;
		wp_cache_get( 'streak-summary:' . $uid, self::GROUP, false, $found );

		return $found;
	}

	/**
	 * Posting busts the author's streak summary.
	 *
	 * @return void
	 */
	public function test_posting_busts_the_streak_cache(): void {
		$this->prime( $this->author );

		$post_id = ( new PostService() )->create( $this->author, array( 'content' => 'a post' ) );
		$this->assertIsInt( $post_id, 'the post fixture must be created' );

		$this->assertFalse(
			$this->is_cached( $this->author ),
			'Posting extends the streak, so the summary must be busted. It is not - the member sees '
			. 'their OLD streak for up to 5 minutes, on a surface whose only job is immediate feedback.'
		);
	}

	/**
	 * Commenting busts the commenter's streak summary.
	 *
	 * @return void
	 */
	public function test_commenting_busts_the_streak_cache(): void {
		$post_id = ( new PostService() )->create( $this->bystander, array( 'content' => 'host post' ) );
		$this->assertIsInt( $post_id, 'the host-post fixture must be created' );

		$this->prime( $this->author );

		$comment_id = ( new CommentService() )->create( $this->author, 'post', (int) $post_id, 'a comment' );
		$this->assertIsInt( $comment_id, 'the comment fixture must be created' );

		$this->assertFalse(
			$this->is_cached( $this->author ),
			'Commenting counts toward the streak (the summary UNIONs bn_comments), so it must bust.'
		);
	}

	/**
	 * Reacting busts the reactor's streak summary.
	 *
	 * @return void
	 */
	public function test_reacting_busts_the_streak_cache(): void {
		$post_id = ( new PostService() )->create( $this->bystander, array( 'content' => 'host post' ) );
		$this->assertIsInt( $post_id, 'the host-post fixture must be created' );

		$this->prime( $this->author );

		( new ReactionService() )->react( $this->author, 'post', (int) $post_id, 'like' );

		$this->assertFalse(
			$this->is_cached( $this->author ),
			'Reacting counts toward the streak (the summary UNIONs bn_reactions), so it must bust.'
		);
	}

	/**
	 * THE ONE THAT MATTERS: we must bust the ACTING member, and nobody else.
	 *
	 * A listener wired from the OLD (wrong) hook docs would read $object_id as a user id, and
	 * therefore bust whichever member happens to have the user id matching the post's id - a
	 * random bystander - while leaving the actual author's stale summary in place.
	 *
	 * This asserts BOTH halves: the author IS busted, and the bystander is NOT. A naive fix
	 * that busts everything (a group flush) also fails this - and would take WidgetCache with
	 * it, since the two share the cache group.
	 *
	 * @return void
	 */
	public function test_busting_is_scoped_to_the_acting_member(): void {
		$post_id = ( new PostService() )->create( $this->bystander, array( 'content' => 'host post' ) );
		$this->assertIsInt( $post_id, 'the host-post fixture must be created' );

		$this->prime( $this->author );
		$this->prime( $this->bystander );

		// The AUTHOR comments. buddynext_comment_created fires as
		// ( $comment_id, $object_type, $object_id, $user_id ) - the user is arg 4, NOT arg 3.
		$comment_id = ( new CommentService() )->create( $this->author, 'post', (int) $post_id, 'a comment' );
		$this->assertIsInt( $comment_id, 'the comment fixture must be created' );

		$this->assertFalse(
			$this->is_cached( $this->author ),
			'the member who acted must have their summary busted'
		);

		$this->assertTrue(
			$this->is_cached( $this->bystander ),
			'A member who did NOTHING must keep their cached summary. If this fails, the listener is '
			. 'busting the wrong person - which is exactly what wiring from the old hook doc would do '
			. '(it read $object_id as a user id) - or it is flushing the whole group, which would also '
			. 'destroy WidgetCache, since they share the cache group.'
		);
	}
}

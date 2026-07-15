<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A severity=flag rule must PUBLISH and report - on every surface, not just posts.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Comments\CommentService;
use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\Moderation\ModerationService;
use BuddyNext\Moderation\SafeguardService;
use BuddyNext\Profile\ProfileService;
use WP_UnitTestCase;

/**
 * A flag that blocks IS editorial pre-approval, which this product does not do.
 *
 * `is_flag_error()` was PRIVATE to PostService, so only post-create recognised a 202
 * "flag" verdict and published-then-reported. Every other caller of the safeguard -
 * post EDIT, comments, direct messages, profile-field saves - returned the WP_Error
 * verbatim and REJECTED the content. The Pro rules admin advertises scope over all of
 * them, so an owner who enabled the shipped "Flag common spam phrases" rule got a
 * silent hard block on four of the five surfaces instead of the flag they asked for.
 *
 * These tests hook the public seam a Pro rule uses (`buddynext_safeguard_check`) and
 * return a 202 flag, then drive each surface for real. They assert BOTH halves:
 *
 *   1. the content exists afterwards (a flag published it), and
 *   2. a system report was filed against it (the flag reached the moderation queue).
 *
 * Asserting only (1) would pass if we simply ignored flags, which would be a worse
 * bug than the one being fixed - content flagged by a rule nobody ever sees.
 *
 * @covers \BuddyNext\Moderation\SafeguardService::is_flag_verdict
 * @covers \BuddyNext\Moderation\ModerationService::auto_flag
 */
class FlagPublishesOnEverySurfaceTest extends WP_UnitTestCase {

	/**
	 * Fresh schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
	}

	/**
	 * Make the safeguard return a flag (202) for content containing a marker word.
	 *
	 * This is the exact shape Pro's RulesService emits (`bnpro_keyword_flagged`,
	 * HTTP 202) and it arrives through the documented public filter, so the test
	 * exercises the real integration seam rather than reaching inside the service.
	 *
	 * @return void
	 */
	private function flag_on( string $marker ): void {
		add_filter(
			'buddynext_safeguard_check',
			static function ( $result, $user_id, $content, $link_url, $context = 'create' ) use ( $marker ) {
				unset( $user_id, $link_url, $context );

				return str_contains( (string) $content, $marker )
					? new \WP_Error(
						'bnpro_keyword_flagged',
						'Your content has been flagged for review.',
						array( 'status' => 202 )
					)
					: $result;
			},
			10,
			5
		);
	}

	/**
	 * Reports filed against an object.
	 *
	 * @return int Number of reports.
	 */
	private function reports_on( string $object_type, int $object_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_reports WHERE object_type = %s AND object_id = %d",
				$object_type,
				$object_id
			)
		);
	}

	/**
	 * The surface that always worked. Kept as the control: if this one breaks, the
	 * shared predicate is wrong, not the individual callers.
	 *
	 * @return void
	 */
	public function test_a_flagged_post_publishes_and_is_reported(): void {
		$this->flag_on( 'spamword' );

		$user_id = self::factory()->user->create();
		$service = new PostService();

		$post_id = $service->create( $user_id, array( 'content' => 'buy spamword now' ) );

		$this->assertIsInt( $post_id, 'A flagged post was rejected. A flag must publish.' );
		$this->assertSame( 1, $this->reports_on( 'post', $post_id ), 'A flagged post published but filed no report, so it never reached the moderation queue.' );
	}

	/**
	 * A flagged EDIT must save. This was broken: update() returned the WP_Error.
	 *
	 * @return void
	 */
	public function test_a_flagged_edit_saves_and_is_reported(): void {
		$user_id = self::factory()->user->create();
		$service = new PostService();

		// Create cleanly first, THEN arm the flag, so we are testing the edit path.
		$post_id = $service->create( $user_id, array( 'content' => 'a perfectly ordinary post' ) );
		$this->assertIsInt( $post_id );

		$this->flag_on( 'spamword' );

		$result = $service->update( $post_id, $user_id, array( 'content' => 'now with spamword in it' ) );

		$this->assertNotWPError(
			$result,
			'A flagged EDIT was rejected. The author could not edit their own post - a flag rule became a hard block.'
		);
		$this->assertSame( 1, $this->reports_on( 'post', $post_id ), 'The flagged edit saved but filed no report.' );
	}

	/**
	 * A flagged COMMENT must publish. This was broken.
	 *
	 * @return void
	 */
	public function test_a_flagged_comment_publishes_and_is_reported(): void {
		$user_id = self::factory()->user->create();
		$post_id = ( new PostService() )->create( $user_id, array( 'content' => 'host post' ) );
		$this->assertIsInt( $post_id );

		$this->flag_on( 'spamword' );

		$comment_id = ( new CommentService() )->create( $user_id, 'post', $post_id, 'reply containing spamword' );

		$this->assertNotWPError( $comment_id, 'A flagged COMMENT was rejected outright instead of published for review.' );
		$this->assertSame( 1, $this->reports_on( 'comment', (int) $comment_id ), 'The flagged comment published but filed no report.' );
	}

	/**
	 * A flagged PROFILE SAVE must persist. This was broken - and it locked the
	 * member out of editing their own profile with no way to see why.
	 *
	 * @return void
	 */
	public function test_a_flagged_profile_save_persists_and_reports_the_member(): void {
		$user_id = self::factory()->user->create();

		$this->flag_on( 'spamword' );

		$result = ( new ProfileService() )->save_profile( $user_id, array( 'bio' => 'my bio has spamword' ) );

		$this->assertNotWPError(
			$result,
			'A flagged PROFILE SAVE was rejected. The member is locked out of their own profile by a rule that was only supposed to flag.'
		);
		$this->assertSame( 1, $this->reports_on( 'user', $user_id ), 'The flagged profile saved but the member never reached the moderation queue.' );
	}

	/**
	 * The predicate itself: a HARD BLOCK must still be a hard block.
	 *
	 * Without this, "fixing" the bug by treating every WP_Error as a flag would pass
	 * every test above while silently disabling moderation entirely.
	 *
	 * @return void
	 */
	public function test_a_hard_block_is_not_a_flag(): void {
		$this->assertFalse(
			SafeguardService::is_flag_verdict( new \WP_Error( 'banned_word', 'Nope.', array( 'status' => 422 ) ) ),
			'A 422 hard block was treated as a flag. Moderation would publish everything.'
		);
		$this->assertFalse(
			SafeguardService::is_flag_verdict( true ),
			'A clean verdict was treated as a flag.'
		);
		$this->assertTrue(
			SafeguardService::is_flag_verdict( new \WP_Error( 'bnpro_keyword_flagged', 'Flagged.', array( 'status' => 202 ) ) ),
			'Pro\'s flag error was not recognised as a flag.'
		);
		$this->assertTrue(
			SafeguardService::is_flag_verdict( new \WP_Error( 'pending_review', 'Held.', array( 'status' => 202 ) ) ),
			'The new-member / duplicate hold was not recognised as a flag.'
		);
	}

	/**
	 * A hard block on a comment still rejects - the fix must not turn moderation off.
	 *
	 * @return void
	 */
	public function test_a_hard_blocked_comment_is_still_rejected(): void {
		$user_id = self::factory()->user->create();
		$post_id = ( new PostService() )->create( $user_id, array( 'content' => 'host post' ) );
		$this->assertIsInt( $post_id );

		add_filter(
			'buddynext_safeguard_check',
			static function ( $result, $user_id, $content, $link_url, $context = 'create' ) {
				unset( $user_id, $link_url, $context );

				return str_contains( (string) $content, 'forbidden' )
					? new \WP_Error( 'banned_word', 'Blocked.', array( 'status' => 422 ) )
					: $result;
			},
			10,
			5
		);

		$comment_id = ( new CommentService() )->create( $user_id, 'post', $post_id, 'this is forbidden' );

		$this->assertWPError(
			$comment_id,
			'A HARD BLOCK on a comment was published. Widening the flag branch must never swallow a genuine rejection.'
		);
	}
}

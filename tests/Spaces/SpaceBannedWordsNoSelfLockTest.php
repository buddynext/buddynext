<?php
/**
 * A space's banned-words list must not be scanned against itself.
 *
 * Regression cover for card 10264294340 round-3: the space custom-field safeguard
 * scan ran over every text/textarea field, and the only core textarea IS the
 * banned-words list. The settings form posts the whole list back on every save, so
 * check_banned_words() matched a listed word inside the submitted list and rejected
 * it — once a space had one banned word, the owner could never add a second or
 * re-save the list. The banned-words field is now excluded from its own scan.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceFieldRegistry;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * The banned-words config field is not gated by its own safeguard scan.
 *
 * @covers \BuddyNext\Spaces\SpaceFieldRegistry::save_for_space
 */
class SpaceBannedWordsNoSelfLockTest extends WP_UnitTestCase {

	/** @var int */
	private $owner = 0;

	/** @var int */
	private $space = 0;

	/**
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->space = (int) ( new SpaceService() )->create(
			$this->owner,
			array( 'name' => 'BW', 'slug' => 'bw-lock', 'type' => 'public' )
		);
	}

	/**
	 * Setting a banned word, then re-saving the list with a second word, must both
	 * succeed — the list is never rejected for containing its own words.
	 *
	 * @return void
	 */
	public function test_banned_words_list_can_be_re_saved(): void {
		$registry = SpaceFieldRegistry::instance();

		$first = $registry->save_for_space( $this->space, array( 'banned_words' => 'forbidword' ), $this->owner );
		$this->assertEmpty( $first['errors'], 'Setting the first banned word must save.' );

		$second = $registry->save_for_space( $this->space, array( 'banned_words' => "forbidword\nsecondword" ), $this->owner );
		$this->assertEmpty( $second['errors'], 'Re-saving the list (with the existing word still in it) must not lock the owner out.' );
	}
}

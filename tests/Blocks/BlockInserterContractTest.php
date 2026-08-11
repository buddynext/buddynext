<?php
/**
 * Regression tests for the block inserter + dynamic-render contract.
 *
 * Locks two block-level regressions that landed in 1.1.x:
 *
 *  - Member-context blocks leaked into the inserter. Seven blocks that only make
 *    sense scoped to a logged-in member or a page author (connect/follow buttons,
 *    the header user menu, my-spaces, the notification bell, the post composer,
 *    the profile-completion bar) must carry `"inserter": false` so an owner
 *    building a page cannot drop them where they render nothing. The twelve
 *    page-content blocks must stay offerable (Basecamp 10184505206, 10184505230).
 *
 *  - A registered block shipping without a render_callback falls back to the
 *    static "Rendered on the frontend" placeholder in the editor instead of a
 *    live ServerSideRender preview. BlockRegistrarTest::test_all_blocks_are_dynamic
 *    checks a HARDCODED list; this asserts the same over every block the registry
 *    actually holds, so a new block added without a callback is caught even if
 *    nobody remembers to extend the list.
 *
 * @package BuddyNext\Tests\Blocks
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Blocks;

/**
 * @covers \BuddyNext\Blocks\BlockRegistrar
 */
class BlockInserterContractTest extends \WP_UnitTestCase {

	/**
	 * Blocks that MUST be hidden from the inserter (member/page-author context).
	 *
	 * Keyed by block slug (the blocks/bn-<slug>/ directory and the part after
	 * `buddynext/` in the block name).
	 *
	 * @var string[]
	 */
	private const MEMBER_CONTEXT_BLOCKS = array(
		'connection-button',
		'follow-button',
		'header-user-menu',
		'my-spaces',
		'notification-bell',
		'post-composer',
		'profile-completion-bar',
	);

	/**
	 * Blocks that MUST remain offerable in the inserter (page-content blocks).
	 *
	 * @var string[]
	 */
	private const PAGE_CONTENT_BLOCKS = array(
		'activity-feed',
		'member-directory',
		'space-directory',
		'space-card',
		'member-card',
		'spaces-showcase',
		'community-activity',
		'members-showcase',
		'trending-hashtags',
		'search-bar',
		'profile-header',
		'profile-fields',
	);

	/**
	 * Decode a block's block.json (the source-of-truth for the inserter flag).
	 *
	 * @param string $slug Block slug, e.g. 'follow-button'.
	 * @return array<string, mixed>
	 */
	private function read_block_json( string $slug ): array {
		$path = BUDDYNEXT_DIR . 'blocks/bn-' . $slug . '/block.json';
		$this->assertFileExists( $path, "block.json missing for '{$slug}'." );
		$data = json_decode( (string) file_get_contents( $path ), true );
		$this->assertIsArray( $data, "block.json for '{$slug}' is not valid JSON." );
		return $data;
	}

	/**
	 * Member-context blocks declare inserter:false so they never offer as page content.
	 *
	 * @param string $slug Block slug.
	 * @dataProvider member_context_block_provider
	 */
	public function test_member_context_blocks_are_not_inserted_as_page_content( string $slug ): void {
		$data     = $this->read_block_json( $slug );
		$supports = $data['supports'] ?? array();

		$this->assertArrayHasKey(
			'inserter',
			$supports,
			"Block '{$slug}' must declare supports.inserter."
		);
		$this->assertFalse(
			$supports['inserter'],
			"Block '{$slug}' must set supports.inserter = false (member-context block)."
		);
	}

	/**
	 * Page-content blocks stay offerable — they must NOT opt out of the inserter.
	 *
	 * @param string $slug Block slug.
	 * @dataProvider page_content_block_provider
	 */
	public function test_page_content_blocks_remain_offerable_in_inserter( string $slug ): void {
		$data     = $this->read_block_json( $slug );
		$supports = $data['supports'] ?? array();

		// Absent inserter key = offerable (WP default). Present must not be false.
		$inserter = $supports['inserter'] ?? true;
		$this->assertNotFalse(
			$inserter,
			"Block '{$slug}' must remain offerable — supports.inserter must not be false."
		);
	}

	/**
	 * Every registered buddynext/* block is dynamic (has a render_callback).
	 *
	 * Registry-driven so a newly registered block with no callback is caught even
	 * if it never makes it into a hardcoded coverage list. A block without a
	 * render_callback renders the static "Rendered on the frontend" placeholder in
	 * the editor instead of a live ServerSideRender preview.
	 */
	public function test_every_registered_buddynext_block_is_dynamic(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		$checked  = 0;

		foreach ( $registry->get_all_registered() as $name => $block ) {
			if ( 0 !== strpos( (string) $name, 'buddynext/' ) ) {
				continue;
			}
			++$checked;
			$this->assertTrue(
				is_callable( $block->render_callback ),
				"Block '{$name}' must have a callable render_callback (dynamic/SSR block)."
			);
		}

		// Guard against the assertion vacuously passing if registration ever breaks.
		$this->assertGreaterThanOrEqual(
			count( self::MEMBER_CONTEXT_BLOCKS ) + count( self::PAGE_CONTENT_BLOCKS ),
			$checked,
			'Fewer buddynext/* blocks are registered than expected — registration may be broken.'
		);
	}

	/**
	 * Data provider: member-context block slugs.
	 *
	 * @return array<string, array{string}>
	 */
	public static function member_context_block_provider(): array {
		$data = array();
		foreach ( self::MEMBER_CONTEXT_BLOCKS as $slug ) {
			$data[ $slug ] = array( $slug );
		}
		return $data;
	}

	/**
	 * Data provider: page-content block slugs.
	 *
	 * @return array<string, array{string}>
	 */
	public static function page_content_block_provider(): array {
		$data = array();
		foreach ( self::PAGE_CONTENT_BLOCKS as $slug ) {
			$data[ $slug ] = array( $slug );
		}
		return $data;
	}
}

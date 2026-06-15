<?php
/**
 * Tests for Gutenberg block and pattern registration.
 *
 * @package BuddyNext\Tests\Blocks
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Blocks;

use BuddyNext\Blocks\BlockRegistrar;

/**
 * Verifies all 18 BuddyNext blocks and 4 block patterns are correctly registered.
 *
 * @covers \BuddyNext\Blocks\BlockRegistrar
 */
class BlockRegistrarTest extends \WP_UnitTestCase {

	/**
	 * All 18 free-tier block names (namespace/slug).
	 *
	 * @var string[]
	 */
	private const BLOCKS = array(
		// Social / Feed.
		'buddynext/activity-feed',
		'buddynext/post-composer',
		'buddynext/trending-hashtags',
		// People.
		'buddynext/member-directory',
		'buddynext/member-card',
		'buddynext/follow-button',
		'buddynext/connection-button',
		// Spaces.
		'buddynext/space-directory',
		'buddynext/space-card',
		'buddynext/my-spaces',
		// Profile.
		'buddynext/profile-header',
		'buddynext/profile-fields',
		'buddynext/profile-completion-bar',
		// Utility.
		'buddynext/registration-form',
		'buddynext/login-form',
		'buddynext/notification-bell',
		'buddynext/header-user-menu',
		'buddynext/search-bar',
	);

	/**
	 * All 4 block pattern names (namespace/slug).
	 *
	 * @var string[]
	 */
	private const PATTERNS = array(
		'buddynext/community-home',
		'buddynext/member-profile',
		'buddynext/spaces-directory',
		'buddynext/member-directory',
	);

	/**
	 * Assert that a block type is registered.
	 *
	 * @param string $block_name Fully-qualified block name (e.g. buddynext/activity-feed).
	 * @dataProvider block_name_provider
	 */
	public function test_block_is_registered( string $block_name ): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		$this->assertTrue(
			$registry->is_registered( $block_name ),
			"Block '{$block_name}' is not registered."
		);
	}

	/**
	 * Data provider: all 18 block names.
	 *
	 * @return array<string, array{string}>
	 */
	public static function block_name_provider(): array {
		$data = array();
		foreach ( self::BLOCKS as $name ) {
			$data[ $name ] = array( $name );
		}
		return $data;
	}

	/**
	 * Assert that a block pattern is registered.
	 *
	 * @param string $pattern_name Fully-qualified pattern name (e.g. buddynext/community-home).
	 * @dataProvider pattern_name_provider
	 */
	public function test_pattern_is_registered( string $pattern_name ): void {
		$registry = \WP_Block_Patterns_Registry::get_instance();
		$this->assertTrue(
			$registry->is_registered( $pattern_name ),
			"Pattern '{$pattern_name}' is not registered."
		);
	}

	/**
	 * Data provider: all 4 pattern names.
	 *
	 * @return array<string, array{string}>
	 */
	public static function pattern_name_provider(): array {
		$data = array();
		foreach ( self::PATTERNS as $name ) {
			$data[ $name ] = array( $name );
		}
		return $data;
	}

	/**
	 * All blocks must be dynamic (have a render_callback).
	 */
	public function test_all_blocks_are_dynamic(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( self::BLOCKS as $name ) {
			$block = $registry->get_registered( $name );
			$this->assertNotNull( $block, "Block '{$name}' not registered." );
			$this->assertNotEmpty(
				$block->render_callback,
				"Block '{$name}' must have a render_callback (dynamic block)."
			);
		}
	}

	/**
	 * All blocks must declare color theme support.
	 */
	public function test_blocks_declare_theme_supports(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( self::BLOCKS as $name ) {
			$block = $registry->get_registered( $name );
			$this->assertNotNull( $block );
			$supports = $block->supports ?? array();
			$this->assertArrayHasKey(
				'color',
				$supports,
				"Block '{$name}' must declare color support."
			);
		}
	}
}

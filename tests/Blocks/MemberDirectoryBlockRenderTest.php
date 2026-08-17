<?php
/**
 * Regression tests for the Member Directory block render.
 *
 * Locks the 1.1.x member-directory block regressions (Basecamp 10184505772):
 *
 *  - The block hand-rolled a bespoke `<ul class="bn-member-list">` of avatar +
 *    name + bio rows that existed nowhere else and drifted from the directory it
 *    is named after. It now delegates to the shared directory grid part, so the
 *    output must carry `.bn-md-grid` / `.bn-md-card` and never the retired
 *    `bn-member-list` / `bn-member-item` / `bn-member-bio` classes.
 *
 *  - When there were more members than perPage it rendered a `bn-load-more`
 *    button carrying data-cursor/data-per-page that nothing read (the block
 *    declares no viewScript), so it invited a click and did nothing. The
 *    overflow affordance must now be a real ANCHOR ("Browse all members") to the
 *    people hub, with no dead load-more control.
 *
 * @package BuddyNext\Tests\Blocks
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Blocks;

use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Blocks\BlockRegistrar::render_member_directory
 */
class MemberDirectoryBlockRenderTest extends \WP_UnitTestCase {

	/**
	 * Viewer the block renders for (get_current_user_id() inside the template).
	 *
	 * @var int
	 */
	private int $viewer_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->viewer_id = self::factory()->user->create();
		wp_set_current_user( $this->viewer_id );
	}

	/**
	 * Render the member-directory block through the real block pipeline.
	 *
	 * do_blocks() parses the block comment and invokes the registered dynamic
	 * render_callback with the block context set up, so get_block_wrapper_attributes()
	 * and the SSR path behave exactly as they do on the front end.
	 *
	 * @param int $per_page perPage attribute.
	 * @return string Rendered HTML.
	 */
	private function render_block_html( int $per_page ): string {
		$markup = sprintf( '<!-- wp:buddynext/member-directory {"perPage":%d} /-->', $per_page );
		return (string) do_blocks( $markup );
	}

	/**
	 * The block renders the SHARED directory grid markup, not a bespoke list.
	 */
	public function test_member_directory_renders_shared_grid(): void {
		self::factory()->user->create_many( 3 );

		$html = $this->render_block_html( 24 );

		$this->assertStringContainsString( 'bn-md-grid', $html, 'Block must render the shared grid wrapper.' );
		$this->assertStringContainsString( 'bn-md-card', $html, 'Block must render shared member cards.' );
		$this->assertStringNotContainsString( 'bn-member-list', $html, 'Retired bespoke list markup must not return.' );
	}

	/**
	 * Overflow is a real "Browse all members" ANCHOR, never a dead load-more button.
	 */
	public function test_member_directory_has_browse_link_not_dead_load_more(): void {
		// Six members, two per page — the service reports a next_cursor, so the
		// block hits its overflow branch.
		self::factory()->user->create_many( 6 );

		$html = $this->render_block_html( 2 );

		$this->assertStringContainsString( 'Browse all members', $html, 'Overflow must offer a browse-all affordance.' );
		$this->assertMatchesRegularExpression(
			'/<a\b[^>]*\bhref=["\'][^"\']+["\'][^>]*>\s*Browse all members/s',
			$html,
			'The browse-all affordance must be an anchor with an href, not a button.'
		);
		$this->assertStringNotContainsString(
			'bn-load-more',
			$html,
			'The dead load-more control must not return.'
		);
	}

	/**
	 * The block never emits the retired bespoke member-row classes.
	 */
	public function test_member_directory_does_not_emit_retired_bespoke_classes(): void {
		self::factory()->user->create_many( 3 );

		$html = $this->render_block_html( 24 );

		$this->assertStringNotContainsString( 'bn-member-item', $html, 'Retired bn-member-item class must not return.' );
		$this->assertStringNotContainsString( 'bn-member-bio', $html, 'Retired bn-member-bio class must not return.' );
		$this->assertStringNotContainsString( 'bn-member-list', $html, 'Retired bn-member-list class must not return.' );
	}

	/**
	 * Render the block with an explicit layout attribute.
	 *
	 * @param string $layout 'list' or 'grid'.
	 * @return string Rendered HTML.
	 */
	private function render_layout( string $layout ): string {
		$markup = sprintf( '<!-- wp:buddynext/member-directory {"perPage":6,"layout":"%s"} /-->', $layout );
		return (string) do_blocks( $markup );
	}

	/**
	 * Layout=List renders the grid in its list mode.
	 *
	 * @return void
	 */
	public function test_list_layout_renders_the_list_class(): void {
		self::factory()->user->create_many( 3 );

		$this->assertStringContainsString(
			'is-list',
			$this->render_layout( 'list' ),
			'the owner picked List, so the grid must render in list mode'
		);
	}

	/**
	 * Layout=Grid does not.
	 *
	 * @return void
	 */
	public function test_grid_layout_does_not_render_the_list_class(): void {
		self::factory()->user->create_many( 3 );

		$this->assertStringNotContainsString(
			'is-list',
			$this->render_layout( 'grid' ),
			'Grid must not render in list mode'
		);
	}

	/**
	 * Either way the grid is pinned against the visitor's view toggle.
	 *
	 * This is the actual regression. The layout classes above were ALREADY correct;
	 * what broke the setting was client-side. The members store's callbacks.init()
	 * runs applyViewClass( readView() ), readView() reads the VISITOR's localStorage
	 * (defaulting to grid), and it toggled `is-list` straight back off on hydrate —
	 * so the owner's choice survived exactly until the JS ran, and the block has no
	 * toggle UI to restore it with.
	 *
	 * `is-view-pinned` is what tells the store to leave an owner-chosen grid alone.
	 * Without it in the markup the store owns the layout again and this whole card
	 * comes back, with the PHP still looking perfectly correct.
	 *
	 * @return void
	 */
	public function test_the_block_grid_is_pinned_against_the_visitor_view_toggle(): void {
		self::factory()->user->create_many( 3 );

		foreach ( array( 'list', 'grid' ) as $layout ) {
			$this->assertStringContainsString(
				'is-view-pinned',
				$this->render_layout( $layout ),
				sprintf(
					'A %s block must be pinned, or the members store overwrites the owner\'s layout '
					. 'from the visitor\'s localStorage on hydrate.',
					$layout
				)
			);
		}
	}
}

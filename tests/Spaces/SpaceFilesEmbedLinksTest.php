<?php
/**
 * Files UI template contract: file links (clean path on the tab, ?bn_doc= when
 * embedded - card 10339911241) and the folder controls, which only a drive
 * manager gets (card 10339911255).
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

/**
 * @coversNothing Template contract for templates/partials/space-files-tab.php.
 */
class SpaceFilesEmbedLinksTest extends \WP_UnitTestCase {

	private function render( bool $embed ): string {
		ob_start();
		buddynext_get_template(
			'partials/space-files-tab.php',
			array(
				'bn_sf_space_id'   => 7,
				'bn_sf_drive_type' => 'space',
				'bn_sf_base_url'   => 'http://example.org/landing/',
				'bn_sf_documents'  => array( array( 'id' => 884, 'title' => 'roster' ) ),
				'bn_sf_doc_query'  => $embed,
			)
		);
		return (string) ob_get_clean();
	}

	public function test_tab_uses_clean_file_links(): void {
		$this->assertStringContainsString( 'href="http://example.org/landing/884/"', $this->render( false ) );
	}

	public function test_embed_uses_the_bn_doc_alias(): void {
		$html = $this->render( true );
		$this->assertStringContainsString( 'http://example.org/landing/?bn_doc=884', $html );
		$this->assertStringNotContainsString( 'landing/884/', $html );
	}

	private function render_folders( bool $manager ): string {
		ob_start();
		buddynext_get_template(
			'partials/space-files-tab.php',
			array(
				'bn_sf_space_id'           => 7,
				'bn_sf_drive_type'         => 'space',
				'bn_sf_base_url'           => 'http://example.org/landing/',
				'bn_sf_folders'            => array( array( 'id' => 5, 'name' => 'Worksheets', 'file_count' => 12, 'folder_count' => 2 ) ),
				'bn_sf_can_write'          => true,
				'bn_sf_can_manage_folders' => $manager,
			)
		);
		return (string) ob_get_clean();
	}

	public function test_manager_gets_folder_controls_with_counts(): void {
		wp_set_current_user( self::factory()->user->create() );
		$html = $this->render_folders( true );
		$this->assertStringContainsString( 'data-bn-folder-manage', $html );
		$this->assertStringContainsString( 'data-bn-folder-new', $html );
		$this->assertStringContainsString( 'bn_trash=1', $html );
		$this->assertMatchesRegularExpression( '/data-bn-folder-delete[^>]*data-bn-files="12"[^>]*data-bn-folders="2"/', $html );
	}

	public function test_search_form_keeps_the_host_page_args(): void {
		ob_start();
		buddynext_get_template(
			'partials/space-files-tab.php',
			array(
				'bn_sf_space_id'   => 7,
				'bn_sf_drive_type' => 'space',
				'bn_sf_base_url'   => 'http://example.org/circle/?id=5&bn_folder=9',
				'bn_sf_documents'  => array( array( 'id' => 884, 'title' => 'roster' ) ),
				'bn_sf_doc_query'  => true,
			)
		);
		$form = (string) strstr( (string) ob_get_clean(), '<form class="bn-files__search"' );
		$form = (string) strstr( $form, '</form>', true );

		$this->assertStringContainsString( '<input type="hidden" name="id" value="5">', $form );
		$this->assertStringNotContainsString( 'name="bn_folder"', $form, 'a new search covers the whole drive' );
	}

	public function test_embed_loads_the_uploader_and_folder_module(): void {
		$queue = new \ReflectionProperty( \WP_Script_Modules::class, 'queue' );
		$queue->setAccessible( true );
		$queue->setValue( wp_script_modules(), array() );

		ob_start();
		buddynext_render_drive_files( 'user', self::factory()->user->create(), 'http://example.org/landing/' );
		ob_end_clean();

		$this->assertContains( '@buddynext/file-upload', $queue->getValue( wp_script_modules() ) );
		$this->assertContains( '@buddynext/space-files', $queue->getValue( wp_script_modules() ) );
	}

	public function test_non_manager_gets_no_folder_controls(): void {
		wp_set_current_user( self::factory()->user->create() );
		$html = $this->render_folders( false );
		$this->assertStringNotContainsString( 'data-bn-folder-', $html );
		$this->assertStringNotContainsString( 'bn_trash=1', $html );
	}
}

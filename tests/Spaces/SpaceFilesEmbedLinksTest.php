<?php
/**
 * The Files UI links a file by clean path on its own tab and by ?bn_doc= when
 * embedded on any other page (buddynext_render_drive_files, card 10339911241).
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
}

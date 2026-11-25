<?php
/**
 * A document card says what kind of file it is, how big, and how old.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\WPMediaVerseBridge;

/**
 * @covers \BuddyNext\Bridges\WPMediaVerseBridge::document_facts
 */
class DocumentCardFactsTest extends \WP_UnitTestCase {

	public function test_office_and_pdf_files_get_a_plain_kind_and_a_size(): void {
		$sheet = WPMediaVerseBridge::document_facts( array( 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ), 2048 );
		$pdf   = WPMediaVerseBridge::document_facts( array( 'mime_type' => 'application/pdf' ), 0 );

		$this->assertSame( 'Excel', $sheet[0]['text'] );
		$this->assertSame( '2 KB', $sheet[1]['text'] );
		$this->assertSame( 'PDF', $pdf[0]['text'] );
		$this->assertSame( '', $pdf[1]['text'], 'No size means the fact is left out by the card.' );
	}

	public function test_an_unknown_type_falls_back_to_the_document_type(): void {
		$facts = WPMediaVerseBridge::document_facts(
			array(
				'mime_type' => 'application/x-thing',
				'doc_type'  => 'archive',
			),
			10
		);
		$this->assertSame( 'Archive', $facts[0]['text'] );
	}
}

<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Every BuddyNext CSV export writes cells a spreadsheet cannot execute.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Admin\Members\MemberExport;
use BuddyNext\Core\Csv;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Core\Csv
 * @covers \BuddyNext\Admin\Members\MemberExport
 */
class CsvTest extends \WP_UnitTestCase {

	/**
	 * Cell in => cell out.
	 *
	 * @return array<string,array{0:mixed,1:string}>
	 */
	public function cells(): array {
		return array(
			'equals formula'     => array( '=1+1', "'=1+1" ),
			'plus'               => array( '+SUM(A1)', "'+SUM(A1)" ),
			'minus expression'   => array( '-2+3', "'-2+3" ),
			'at'                 => array( '@cmd', "'@cmd" ),
			'leading tab'        => array( "\t=1", "'\t=1" ),
			'leading CR'         => array( "\r=1", "'\r=1" ),
			'negative number'    => array( '-5', '-5' ),
			'negative float'     => array( '-5.25', '-5.25' ),
			'integer'            => array( 42, '42' ),
			'plain text'         => array( 'Jane Doe', 'Jane Doe' ),
			'empty'              => array( '', '' ),
			'formula mid-string' => array( 'a=b', 'a=b' ),
			'null'               => array( null, '' ),
		);
	}

	/**
	 * @dataProvider cells
	 *
	 * @param mixed  $in  Raw cell.
	 * @param string $out Expected cell.
	 */
	public function test_cell( $in, string $out ): void {
		$this->assertSame( $out, Csv::cell( $in ) );
	}

	public function test_row_is_rfc_4180_with_no_backslash_escaping(): void {
		$stream = fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		Csv::row( $stream, array( 'a\\"b', '=HYPERLINK("x")', -5 ) );
		rewind( $stream );
		$line = (string) stream_get_contents( $stream );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$this->assertSame( "\"a\\\"\"b\",\"'=HYPERLINK(\"\"x\"\")\",-5\n", $line );
	}

	public function test_member_export_defuses_a_formula_display_name(): void {
		Installer::run();
		self::factory()->user->create( array( 'display_name' => '=1+1' ) );

		ob_start();
		( new MemberExport() )->export_members_csv();
		$csv = (string) ob_get_clean();

		$this->assertStringContainsString( "'=1+1", $csv, 'The display name must arrive as text.' );
		$this->assertDoesNotMatchRegularExpression( '/(^|,)=1\+1/m', $csv, 'A display name was written as a live formula.' );
	}
}

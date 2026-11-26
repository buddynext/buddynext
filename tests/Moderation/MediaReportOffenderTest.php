<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * A report on media names who posted it.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;

/**
 * The queue resolved offenders for posts, comments and messages only, so a media
 * report showed no offender and no member actions (card 10501878423). Extension
 * types answer the same filter the takedown notice already asks.
 *
 * @covers \BuddyNext\Moderation\ModerationService::get_queue
 */
class MediaReportOffenderTest extends \WP_UnitTestCase {

	private int $uploader = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->uploader = self::factory()->user->create( array( 'display_name' => 'Media Uploader' ) );
		$reporter       = self::factory()->user->create();

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_reports',
			array(
				'reporter_id' => $reporter,
				'object_type' => 'media',
				'object_id'   => 4242,
				'reason'      => 'spam',
				'status'      => 'pending',
			)
		);
	}

	private function media_row(): array {
		$result = ( new ModerationService() )->get_queue( array( 'status' => 'pending', 'enrich' => true ) );
		foreach ( (array) ( $result['items'] ?? $result ) as $item ) {
			if ( 'media' === $item['object_type'] ) {
				return $item;
			}
		}
		$this->fail( 'the media report is in the queue' );
	}

	public function test_a_media_report_names_its_uploader(): void {
		$uploader = $this->uploader;
		add_filter(
			'buddynext_removed_content_author',
			static fn( $author, $type, $id ) => ( 'media' === $type && 4242 === (int) $id ) ? $uploader : $author,
			10,
			3
		);

		$row = $this->media_row();
		$this->assertSame( $uploader, (int) $row['offender_id'] );
		$this->assertSame( 'Media Uploader', $row['offender_name'] ?? '' );
	}

	public function test_with_no_answer_the_offender_stays_unknown(): void {
		remove_all_filters( 'buddynext_removed_content_author' );
		$this->assertSame( 0, (int) $this->media_row()['offender_id'] );
	}
}

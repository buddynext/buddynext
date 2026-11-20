<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Writing for a space: the hidden bn_space field in Member Blog's form and the
 * meta it stores through Member Blog's save filter.
 *
 * Runs in separate processes: the bridge reads Member Blog functions that are
 * stubbed here and cannot be removed again for the rest of the suite.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\MemberBlogBridge;
use BuddyNext\Core\Installer;
use BuddyNext\Feed\BlogPostListener;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * @covers \BuddyNext\Bridges\MemberBlogBridge
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class MemberBlogSpaceFieldTest extends \WP_UnitTestCase {

	private MemberBlogBridge $bridge;
	private int $owner;
	private int $writer;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		if ( ! function_exists( 'bp_member_blog_user_can_create' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only stub of a partner API, defined in the child process.
			eval( 'function bp_member_blog_user_can_create( $user_id ) { return in_array( (int) $user_id, $GLOBALS["bn_test_mb_writers"] ?? array(), true ); }' );
		}

		$this->owner                   = self::factory()->user->create();
		$this->writer                  = self::factory()->user->create( array( 'role' => 'author' ) );
		$GLOBALS['bn_test_mb_writers'] = array( $this->writer );
		$this->bridge                  = new MemberBlogBridge();
		$_GET                          = array();
		$_POST                         = array();
	}

	public function tear_down(): void {
		$_GET  = array();
		$_POST = array();
		parent::tear_down();
	}

	private function space( string $type = 'open', bool $join = true ): int {
		$id = ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'Field ' . $type,
				'slug' => 'field-' . $type . '-' . wp_rand( 10000, 99999 ),
				'type' => $type,
			)
		);
		$this->assertIsInt( $id );
		if ( $join ) {
			( new SpaceMemberService() )->join( (int) $id, $this->writer );
		}
		return (int) $id;
	}

	private function field_for( int $user_id, int $post_id ): string {
		wp_set_current_user( $user_id );
		ob_start();
		$this->bridge->render_space_field( $post_id );
		return (string) ob_get_clean();
	}

	/**
	 * Run the save filter as Member Blog does: create (post id 0) or update.
	 *
	 * @param mixed $posted  The posted bn_space value, or null for no field.
	 * @param int   $post_id Post being updated, 0 on create.
	 * @return array<string,mixed>
	 */
	private function save( $posted, int $post_id = 0 ): array {
		wp_set_current_user( $this->writer );
		$_POST = null === $posted ? array() : array( 'bn_space' => $posted );
		$data  = array(
			'post_title'  => 'T',
			'post_type'   => 'post',
			'post_author' => $this->writer,
		);
		if ( $post_id > 0 ) {
			$data['ID'] = $post_id;
		}
		return (array) $this->bridge->save_space_choice( $data, $post_id );
	}

	public function test_writer_gets_the_field_on_a_new_post_from_the_page_arg(): void {
		$space            = $this->space();
		$_GET['bn_space'] = (string) $space;

		$this->assertStringContainsString( 'name="bn_space" value="' . $space . '"', $this->field_for( $this->writer, 0 ) );
	}

	public function test_no_field_for_a_non_writer_or_an_invalid_page_arg(): void {
		$space            = $this->space();
		$_GET['bn_space'] = (string) $space;
		$this->assertSame( '', $this->field_for( $this->owner, 0 ), 'a member Member Blog does not let write gets nothing' );

		$_GET['bn_space'] = (string) $this->space( 'private' );
		$this->assertSame( '', $this->field_for( $this->writer, 0 ), 'a private space is never offered' );
	}

	public function test_an_edit_carries_the_stored_choice(): void {
		$space   = $this->space();
		$post_id = self::factory()->post->create( array( 'post_author' => $this->writer ) );
		update_post_meta( $post_id, BlogPostListener::SPACE_META, $space );

		$this->assertStringContainsString( 'value="' . $space . '"', $this->field_for( $this->writer, $post_id ) );

		$plain            = self::factory()->post->create( array( 'post_author' => $this->writer ) );
		$_GET['bn_space'] = (string) $space;
		$this->assertSame( '', $this->field_for( $this->writer, $plain ), 'an edit without a stored space takes nothing from the URL' );
	}

	public function test_a_valid_public_space_is_stored(): void {
		$space = $this->space();
		$data  = $this->save( (string) $space );

		$this->assertSame( $space, $data['meta_input'][ BlogPostListener::SPACE_META ] ?? null );
	}

	public function test_invalid_choices_are_refused_on_create(): void {
		$archived = $this->space();
		( new SpaceService() )->archive( $archived, $this->owner );
		wp_cache_flush();

		$refused = array(
			'forged'     => '999999',
			'empty'      => '',
			'array'      => array( '1' ),
			'private'    => (string) $this->space( 'private' ),
			'secret'     => (string) $this->space( 'secret' ),
			'non-member' => (string) $this->space( 'open', false ),
			'archived'   => (string) $archived,
		);
		foreach ( $refused as $label => $value ) {
			$data = $this->save( $value );
			$this->assertArrayNotHasKey( BlogPostListener::SPACE_META, (array) ( $data['meta_input'] ?? array() ), "{$label} is not stored" );
		}
	}

	public function test_who_can_post_owner_refuses_a_member(): void {
		$space = $this->space();
		update_space_meta( $space, 'who_can_post', 'owner' );
		wp_cache_flush();

		$this->assertArrayNotHasKey( 'meta_input', $this->save( (string) $space ) );
	}

	public function test_an_invalid_or_cleared_choice_on_an_edit_zeroes_the_stored_one(): void {
		$space   = $this->space();
		$post_id = self::factory()->post->create( array( 'post_author' => $this->writer ) );
		update_post_meta( $post_id, BlogPostListener::SPACE_META, $space );

		$this->assertSame( 0, $this->save( '', $post_id )['meta_input'][ BlogPostListener::SPACE_META ] ?? null, 'a cleared choice' );
		$this->assertSame( 0, $this->save( (string) $this->space( 'private' ), $post_id )['meta_input'][ BlogPostListener::SPACE_META ] ?? null, 'a refused choice' );
	}

	public function test_no_field_leaves_the_stored_choice_alone(): void {
		$space   = $this->space();
		$post_id = self::factory()->post->create( array( 'post_author' => $this->writer ) );
		update_post_meta( $post_id, BlogPostListener::SPACE_META, $space );

		$this->assertArrayNotHasKey( 'meta_input', $this->save( null, $post_id ) );
	}

	public function test_meta_input_reaches_the_post_and_its_card(): void {
		$space = $this->space();
		$data  = $this->save( (string) $space );
		unset( $data['ID'] );
		$data['post_status']  = 'publish';
		$data['post_content'] = 'Body.';

		$post_id = (int) wp_insert_post( $data );

		$this->assertSame( (string) $space, get_post_meta( $post_id, BlogPostListener::SPACE_META, true ) );
		global $wpdb;
		$card_space = (int) $wpdb->get_var( $wpdb->prepare( "SELECT space_id FROM {$wpdb->prefix}bn_posts WHERE id = %d", BlogPostListener::card_id_for_post( $post_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( $space, $card_space, 'the card is in the space on the first publish' );
	}
}

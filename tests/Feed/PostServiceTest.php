<?php
/**
 * Tests for PostService.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\IntegrationActivity;
use BuddyNext\Feed\PostService;

/**
 * @covers \BuddyNext\Feed\PostService
 */
class PostServiceTest extends \WP_UnitTestCase {

	private PostService $service;
	private int $alice;
	private int $bob;
	private int $admin;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new PostService();
		$this->alice   = self::factory()->user->create();
		$this->bob     = self::factory()->user->create();
		$this->admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	public function test_create_returns_id(): void {
		$id = $this->service->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Hello world',
				'privacy' => 'public',
			)
		);

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_create_returns_error_for_invalid_type(): void {
		$result = $this->service->create(
			$this->alice,
			array(
				'type'    => 'invalid_type',
				'content' => 'Bad type',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_post_type', $result->get_error_code() );
	}

	public function test_create_fires_action(): void {
		$fired = array();
		add_action(
			'buddynext_post_created',
			function ( int $post_id, int $user_id, string $type ) use ( &$fired ): void {
				$fired[] = array( $post_id, $user_id, $type );
			},
			10,
			3
		);

		$id = $this->service->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Fire test',
			)
		);

		$this->assertCount( 1, $fired );
		$this->assertSame( $id, $fired[0][0] );
		$this->assertSame( $this->alice, $fired[0][1] );
		$this->assertSame( 'text', $fired[0][2] );
	}

	public function test_get_returns_post_data(): void {
		$id   = $this->service->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Readable post',
				'privacy' => 'public',
			)
		);
		$post = $this->service->get( $id );

		$this->assertIsArray( $post );
		$this->assertSame( $id, $post['id'] );
		$this->assertSame( $this->alice, $post['user_id'] );
		$this->assertSame( 'Readable post', $post['content'] );
		$this->assertSame( 'text', $post['type'] );
		$this->assertSame( 'public', $post['privacy'] );
	}

	public function test_get_returns_null_for_unknown_post(): void {
		$this->assertNull( $this->service->get( 99999 ) );
	}

	public function test_get_decodes_media_ids_json(): void {
		global $wpdb;

		// This exercises ONLY the JSON round-trip in get(). The ids are written
		// straight to the column rather than through create(), because
		// authorize_media_ids() legitimately refuses ids the media engine cannot
		// resolve — and the engine is absent in the test environment, so every id
		// is unresolvable here. Routing through create() would test the guard, not
		// the decode. (It used to pass by creating as an admin, back when the admin
		// exemption skipped the whole check; that exemption is what let a
		// fabricated id publish a dangling attachment.)
		// media_ids is passed to create() rather than only written by the UPDATE
		// below: a photo post with no text AND no media is an empty post, which
		// create() now refuses (empty_post). This test is about how media_ids is
		// DECODED on read, so it needs a valid post to read — it was previously
		// creating an invalid one as a shortcut and then patching it in SQL.
		$id = $this->service->create(
			$this->admin,
			array(
				'type'      => 'photo',
				'content'   => '',
				'media_ids' => array( 10 ),
			)
		);

		$this->assertIsInt( $id, 'Fixture post was not created, so the decode assertion below would test nothing.' );

		$wpdb->update(
			$wpdb->prefix . 'bn_posts',
			array( 'media_ids' => wp_json_encode( array( 10, 20, 30 ) ) ),
			array( 'id' => $id )
		);
		wp_cache_flush();

		$post = $this->service->get( $id );

		$this->assertSame( array( 10, 20, 30 ), $post['media_ids'] );
	}

	public function test_update_changes_content(): void {
		$id = $this->service->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Original',
			)
		);

		$result = $this->service->update( $id, $this->alice, array( 'content' => 'Updated' ) );

		$this->assertTrue( $result );
		$this->assertSame( 'Updated', $this->service->get( $id )['content'] );
	}

	public function test_update_sets_edited_at(): void {
		$id = $this->service->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Before edit',
			)
		);

		$this->service->update( $id, $this->alice, array( 'content' => 'After edit' ) );
		$post = $this->service->get( $id );

		$this->assertNotNull( $post['edited_at'] );
	}

	public function test_update_requires_owner(): void {
		$id = $this->service->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Alice post',
			)
		);

		$result = $this->service->update( $id, $this->bob, array( 'content' => 'Bob changes' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'not_post_owner', $result->get_error_code() );
	}

	public function test_delete_removes_post(): void {
		$id = $this->service->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Delete me',
			)
		);

		$this->service->delete( $id, $this->alice );

		$this->assertNull( $this->service->get( $id ) );
	}

	public function test_delete_requires_owner(): void {
		$id = $this->service->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Alice post',
			)
		);

		$result = $this->service->delete( $id, $this->bob );

		$this->assertWPError( $result );
		$this->assertSame( 'not_post_owner', $result->get_error_code() );
		$this->assertNotNull( $this->service->get( $id ) );
	}

	public function test_pin_sets_flag(): void {
		$id = $this->service->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Pin me',
			)
		);

		$this->service->pin( $id, $this->alice );

		$this->assertSame( 1, $this->service->get( $id )['is_pinned'] );
	}

	public function test_unpin_clears_flag(): void {
		$id = $this->service->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Pinned',
			)
		);
		$this->service->pin( $id, $this->alice );

		$this->service->unpin( $id, $this->alice );

		$this->assertSame( 0, $this->service->get( $id )['is_pinned'] );
	}

	public function test_create_poll_creates_options(): void {
		$id = $this->service->create(
			$this->alice,
			array(
				'type'    => 'poll',
				'content' => 'Best colour?',
				'options' => array( 'Red', 'Green', 'Blue' ),
			)
		);

		$post = $this->service->get( $id );
		$this->assertSame( 'poll', $post['type'] );
		$this->assertCount( 3, $post['poll_options'] );
		$this->assertSame( 'Red', $post['poll_options'][0]['option_text'] );
	}

	public function test_create_poll_requires_at_least_two_options(): void {
		$result = $this->service->create(
			$this->alice,
			array(
				'type'    => 'poll',
				'content' => 'Invalid poll',
				'options' => array( 'Only one' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'poll_requires_options', $result->get_error_code() );
	}

	/**
	 * Privacy is normalised to a valid ENUM member at the service, so the composer
	 * pseudo-value 'members' (and any bad value) can never reach bn_posts.privacy as
	 * an empty string and destroy the post's audience (card 10284912236 item 3).
	 *
	 * @return void
	 */
	public function test_create_normalises_privacy_to_a_valid_enum(): void {
		global $wpdb;

		$cases = array(
			'members'       => 'public',       // UI pseudo-value -> public.
			'garbage_value' => 'public',       // unknown -> configured default (public).
			'connections'   => 'connections',  // valid -> unchanged.
			'private'       => 'private',       // valid -> unchanged.
			'space_members' => 'space_members', // valid -> unchanged.
		);

		foreach ( $cases as $input => $expected ) {
			$id = $this->service->create( $this->alice, array( 'type' => 'text', 'content' => 'x', 'privacy' => $input ) );
			$this->assertIsInt( $id, "create() should succeed for privacy={$input}" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT privacy FROM {$wpdb->prefix}bn_posts WHERE id = %d", $id ) );
			$this->assertSame( $expected, $stored, "privacy={$input} must store as {$expected}, never ''." );
			$this->assertNotSame( '', $stored, 'Privacy must never be stored as an empty ENUM value.' );
		}
	}

	/**
	 * Edit is offered only on cards whose text the inline editor can edit.
	 *
	 * @return void
	 */
	public function test_has_editable_text_matches_what_the_card_renders(): void {
		$this->assertTrue( PostService::has_editable_text( 'text', '' ) );
		$this->assertTrue( PostService::has_editable_text( 'announcement', 'Hello' ) );

		foreach ( array( 'photo', 'file', 'link' ) as $type ) {
			$this->assertTrue( PostService::has_editable_text( $type, 'A caption' ), "$type with a caption is editable" );
			$this->assertTrue( PostService::has_editable_text( $type, "  \n" ), "$type without a caption gets a caption box" );
		}

		foreach ( array( 'share', 'poll' ) as $type ) {
			$this->assertTrue( PostService::has_editable_text( $type, 'A caption' ), "$type with a caption is editable" );
			$this->assertFalse( PostService::has_editable_text( $type, "  \n" ), "$type without a caption has no text to edit" );
		}

		foreach ( array( 'media', 'document', 'event', 'listing', 'course', 'badge' ) as $type ) {
			$typed = static fn() => '<div>card</div>';
			add_filter( 'buddynext_render_post_body_' . $type, $typed );
			$this->assertFalse( PostService::has_editable_text( $type, '' ), "$type is a typed card" );
			$this->assertFalse( PostService::has_editable_text( $type, 'Text' ), "$type is a typed card" );
			remove_filter( 'buddynext_render_post_body_' . $type, $typed );
		}

		$this->assertFalse( PostService::has_editable_text( 'discussion', 'Topic' ), 'a forum discussion is edited in the forum' );

		// A typed card drawn by a renderer (blog article, forum discussion) is edited at its source.
		$renderer = static function () {
			return '<div>card</div>';
		};
		add_filter( 'buddynext_render_post_body_qa_typed', $renderer );
		$this->assertFalse( PostService::has_editable_text( 'qa_typed', 'Body' ) );
		remove_filter( 'buddynext_render_post_body_qa_typed', $renderer );
		$this->assertFalse( PostService::has_editable_text( 'qa_typed', 'Body' ), 'unknown type, no renderer: its add-on is gone' );

		$allow = static fn( $types ) => array_merge( (array) $types, array( 'qa_typed' ) );
		add_filter( 'buddynext_feed_allowed_post_types', $allow );
		$this->assertTrue( PostService::has_editable_text( 'qa_typed', 'Body' ), 'a type the site added and draws as text stays editable' );
		remove_filter( 'buddynext_feed_allowed_post_types', $allow );
	}

	/**
	 * A partner's card with its partner gone is neutral and never editable, while
	 * the member's own native posts are untouched.
	 *
	 * @return void
	 */
	public function test_orphaned_cards_are_neutral_and_not_editable(): void {
		foreach ( array( 'listing', 'course', 'event', 'job', 'resume', 'badge', 'media', 'document' ) as $type ) {
			$held = $GLOBALS['wp_filter'][ 'buddynext_render_post_body_' . $type ] ?? null;
			unset( $GLOBALS['wp_filter'][ 'buddynext_render_post_body_' . $type ] );
			$this->assertTrue( PostService::is_orphaned_card( $type ), "$type with no renderer" );
			$this->assertFalse( PostService::has_editable_text( $type, 'added a new thing' ), "$type is never plain text" );
			if ( null !== $held ) {
				$GLOBALS['wp_filter'][ 'buddynext_render_post_body_' . $type ] = $held;
			}

			$typed = static fn() => '<div>card</div>';
			add_filter( 'buddynext_render_post_body_' . $type, $typed );
			$this->assertFalse( PostService::is_orphaned_card( $type ), "$type with its renderer" );
			remove_filter( 'buddynext_render_post_body_' . $type, $typed );
		}
		foreach ( array( 'text', 'activity', 'announcement', 'photo', 'file', 'link', 'poll', 'share' ) as $type ) {
			$this->assertFalse( PostService::is_orphaned_card( $type ), "$type is drawn by BuddyNext itself" );
		}

		$held = $GLOBALS['wp_filter']['buddynext_discussion_card_url'] ?? null;
		unset( $GLOBALS['wp_filter']['buddynext_discussion_card_url'] );
		$this->assertTrue( PostService::is_orphaned_card( 'discussion' ), 'no bridge left to confirm the link' );
		add_filter( 'buddynext_discussion_card_url', '__return_empty_string' );
		$this->assertFalse( PostService::is_orphaned_card( 'discussion' ), 'its bridge answers' );
		remove_filter( 'buddynext_discussion_card_url', '__return_empty_string' );
		if ( null !== $held ) {
			$GLOBALS['wp_filter']['buddynext_discussion_card_url'] = $held;
		}

		$html = IntegrationActivity::render_orphaned_card(
			array(
				'bn_post_type' => 'listing',
				'link_preview' => array(
					'url'   => 'https://example.test/gone/',
					'title' => 'Corner <b>Bakery</b>',
				),
			)
		);
		$this->assertStringContainsString( 'Corner &lt;b&gt;Bakery&lt;/b&gt;', $html );
		$this->assertStringContainsString( 'no longer available', $html );
		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringNotContainsString( 'example.test', $html );

		$private = IntegrationActivity::render_orphaned_card(
			array(
				'bn_post_type' => 'media',
				'link_preview' => array( 'title' => 'Private video' ),
			)
		);
		$this->assertStringNotContainsString( 'Private video', $private, 'a title only the partner may reveal stays hidden' );
	}

	/**
	 * The API refuses a body edit on a partner's card, whether or not its partner is
	 * active, while an ordinary text post and a site admin keep working.
	 *
	 * @return void
	 */
	public function test_update_refuses_a_body_edit_on_a_partner_card(): void {
		$member = self::factory()->user->create();
		$card   = IntegrationActivity::publish( $member, 'added a new listing', 'https://example.test/l/', 'A listing', 'listing' );
		$this->assertGreaterThan( 0, (int) $card );

		$refused = $this->service->update( (int) $card, $member, array( 'content' => 'overwritten' ) );
		$this->assertWPError( $refused );
		$this->assertSame( 'post_not_editable', $refused->get_error_code() );
		$this->assertSame( 'added a new listing', (string) $this->service->get( (int) $card )['content'] );

		$this->assertNotWPError( $this->service->update( (int) $card, $member, array( 'privacy' => 'public' ) ), 'other fields stay editable' );
		$this->assertNotWPError( $this->service->update( (int) $card, $this->admin, array( 'content' => 'redacted' ) ), 'an admin may still redact' );

		$text = $this->service->create( $member, array( 'type' => 'text', 'content' => 'Mine' ) );
		$this->assertTrue( $this->service->update( (int) $text, $member, array( 'content' => 'Mine, edited' ) ) );
	}

	/**
	 * hydrate() carries the space's type, so a card can tell a genuinely narrowed
	 * audience from one an open space does not enforce (card 10322304407): the lock
	 * badge for a 'space_members' post in an OPEN space would otherwise lie.
	 */
	public function test_hydrate_carries_space_type(): void {
		$space_id = (int) ( new \BuddyNext\Spaces\SpaceService() )->create(
			$this->admin,
			array(
				'name' => 'Hydrate Type ' . wp_generate_password( 6, false, false ),
				'slug' => 'hydrate-type-' . strtolower( wp_generate_password( 8, false, false ) ),
				'type' => 'open',
			)
		);
		$this->assertGreaterThan( 0, $space_id );

		$post_id = $this->service->create(
			$this->admin,
			array(
				'type'     => 'text',
				'content'  => 'In an open space',
				'space_id' => $space_id,
				'privacy'  => 'space_members',
			)
		);
		$this->assertIsInt( $post_id );

		$space_post = $this->service->get( $post_id );
		$this->assertSame( 'open', $space_post['space_type'], 'a space post should hydrate its space type' );

		// A non-space post reports no space type (the marker logic then never fires).
		$plain_id   = $this->service->create( $this->admin, array( 'type' => 'text', 'content' => 'No space' ) );
		$plain_post = $this->service->get( $plain_id );
		$this->assertSame( '', $plain_post['space_type'], 'a non-space post carries an empty space type' );
	}

	/**
	 * A third-party feed card type registers through the filter instead of forking
	 * PostService (card 10264296011): the type is accepted by create(), the
	 * built-ins are never dropped even by a filter that returns an empty set.
	 */
	public function test_allowed_types_filter_adds_a_type_without_dropping_built_ins(): void {
		// A custom type is rejected until it is registered.
		$rejected = $this->service->create( $this->alice, array( 'type' => 'petition', 'content' => 'x' ) );
		$this->assertWPError( $rejected );

		$add = static function ( array $types ): array {
			$types[] = 'petition';
			return $types;
		};
		add_filter( 'buddynext_feed_allowed_post_types', $add );
		$this->assertContains( 'petition', PostService::allowed_types() );
		$this->assertContains( 'text', PostService::allowed_types(), 'built-ins remain' );
		$accepted = $this->service->create( $this->alice, array( 'type' => 'petition', 'content' => 'x' ) );
		$this->assertIsInt( $accepted );
		remove_filter( 'buddynext_feed_allowed_post_types', $add );

		// A filter that tries to WIPE the set cannot remove the built-ins.
		$wipe = static function (): array {
			return array();
		};
		add_filter( 'buddynext_feed_allowed_post_types', $wipe );
		$this->assertContains( 'text', PostService::allowed_types(), 'a filter cannot drop a core type' );
		remove_filter( 'buddynext_feed_allowed_post_types', $wipe );
	}
}

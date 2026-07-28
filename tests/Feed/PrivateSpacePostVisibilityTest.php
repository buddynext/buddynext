<?php
/**
 * A post inside a PRIVATE space is not readable by non-members.
 *
 * Regression cover for a critical leak. Four separate read paths hand-rolled the
 * space gate and all four asked `is_hidden_from_non_members()`, which is true for
 * SECRET only. A `private` (request-to-join) space is not hidden — it is listed
 * and gated — so every one of those checks fell straight through and a fully
 * anonymous visitor holding a post id read the post in full, while the space's
 * own feed correctly refused them.
 *
 * The gate is now `SpaceVisibility::can_view_content()` — the documented single
 * decision point — which is derived from the type's VISIBILITY, so a custom
 * registered type is covered without editing any of these call sites.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\Spaces\SpaceService;
use WP_Error;

/**
 * Space-content gating on the single-post read paths.
 *
 * @covers \BuddyNext\Feed\PostService::visibility_error
 * @covers \BuddyNext\Feed\PostService::filter_visible
 */
class PrivateSpacePostVisibilityTest extends \WP_UnitTestCase {

	/**
	 * Post service under test.
	 *
	 * @var PostService
	 */
	private $posts;

	/**
	 * Space owner, who is also the post author.
	 *
	 * @var int
	 */
	private $owner = 0;

	/**
	 * A logged-in member of the space.
	 *
	 * @var int
	 */
	private $member = 0;

	/**
	 * A logged-in user who is not in the space.
	 *
	 * @var int
	 */
	private $stranger = 0;

	/**
	 * Site administrator.
	 *
	 * @var int
	 */
	private $admin = 0;

	/**
	 * Post ids keyed by the space type they live in.
	 *
	 * @var array<string, int>
	 */
	private $post_ids = array();

	/**
	 * Build one space of each type with a post in it.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->owner    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->member   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->stranger = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );

		global $wpdb;

		$this->posts = new PostService();
		$spaces      = new SpaceService();

		foreach ( array( 'open', 'private', 'secret' ) as $type ) {
			$space_id = $spaces->create(
				$this->owner,
				array(
					'name' => ucfirst( $type ) . ' Space',
					'slug' => $type . '-space',
					'type' => $type,
				)
			);
			$this->assertIsInt( $space_id, 'Could not create the ' . $type . ' space.' );

			// Seed the membership row directly: join() on a private space would
			// correctly land in 'pending', and this fixture needs an ACTIVE member.
			$inserted = $wpdb->insert(
				$wpdb->prefix . 'bn_space_members',
				array(
					'space_id' => $space_id,
					'user_id'  => $this->member,
					'role'     => 'member',
					'status'   => 'active',
				)
			);
			$this->assertSame( 1, $inserted, 'Membership fixture row was not written for the ' . $type . ' space.' );

			$post_id = $this->posts->create(
				$this->owner,
				array(
					'content'  => 'Body of the ' . $type . ' post.',
					'space_id' => $space_id,
					'privacy'  => 'public',
					'type'     => 'text',
				)
			);
			$this->assertIsInt( $post_id, 'Could not create the ' . $type . ' post.' );

			$this->post_ids[ $type ] = $post_id;
		}
	}

	/**
	 * Assert the gate's verdict for one viewer on one post.
	 *
	 * @param string $type    Space type the post lives in.
	 * @param int    $viewer  Viewer user ID (0 = anonymous).
	 * @param bool   $allowed Whether the viewer should be able to read it.
	 * @param string $who     Label used in the failure message.
	 * @return void
	 */
	private function assertVisibility( string $type, int $viewer, bool $allowed, string $who ): void {
		$post_id = $this->post_ids[ $type ];
		$error   = $this->posts->visibility_error( $post_id, $viewer );

		if ( $allowed ) {
			$this->assertNull( $error, $who . ' was refused a post in a ' . $type . ' space.' );
		} else {
			$this->assertInstanceOf( WP_Error::class, $error, $who . ' could read a post in a ' . $type . ' space.' );
			$this->assertSame( 404, $error->get_error_data()['status'], 'Existence of the row was leaked by the status code.' );
		}

		// filter_visible() is the batch sibling and must agree exactly, or a post
		// refused by the deeplink still appears in search / engagement lists.
		$batch = $this->posts->filter_visible( array( $post_id ), $viewer );
		$this->assertSame(
			$allowed ? array( $post_id ) : array(),
			$batch,
			'filter_visible() disagreed with visibility_error() for ' . $who . ' on a ' . $type . ' space.'
		);
	}

	/**
	 * The reported leak: anonymous read of a private-space post.
	 *
	 * @return void
	 */
	public function test_anonymous_cannot_read_private_space_post(): void {
		$this->assertVisibility( 'private', 0, false, 'An anonymous visitor' );
	}

	/**
	 * Logging in is not membership — a stranger is refused too.
	 *
	 * @return void
	 */
	public function test_logged_in_non_member_cannot_read_private_space_post(): void {
		$this->assertVisibility( 'private', $this->stranger, false, 'A logged-in non-member' );
	}

	/**
	 * Members, the owner and site admins keep their access.
	 *
	 * @return void
	 */
	public function test_members_owner_and_admin_can_read_private_space_post(): void {
		$this->assertVisibility( 'private', $this->member, true, 'An active member' );
		$this->assertVisibility( 'private', $this->owner, true, 'The space owner' );
		$this->assertVisibility( 'private', $this->admin, true, 'A site administrator' );
	}

	/**
	 * Secret spaces were already gated and must stay gated.
	 *
	 * @return void
	 */
	public function test_secret_space_post_stays_gated(): void {
		$this->assertVisibility( 'secret', 0, false, 'An anonymous visitor' );
		$this->assertVisibility( 'secret', $this->stranger, false, 'A logged-in non-member' );
		$this->assertVisibility( 'secret', $this->member, true, 'An active member' );
	}

	/**
	 * Open spaces must NOT become members-only — that would be the mirror-image
	 * regression, and it is the one a too-broad gate would cause.
	 *
	 * @return void
	 */
	public function test_open_space_post_stays_public(): void {
		$this->assertVisibility( 'open', 0, true, 'An anonymous visitor' );
		$this->assertVisibility( 'open', $this->stranger, true, 'A logged-in non-member' );
	}

	/**
	 * The gate is visibility-derived, not a hardcoded type list: a custom type
	 * registered with private visibility is gated without touching a call site.
	 *
	 * @return void
	 */
	public function test_custom_private_visibility_type_is_gated(): void {
		$registry = \BuddyNext\Spaces\SpaceTypeRegistry::instance();

		$this->assertTrue(
			$registry->content_requires_membership( 'private' ),
			'private must require membership for content.'
		);
		$this->assertFalse(
			$registry->is_hidden_from_non_members( 'private' ),
			'private is listed-but-gated, not hidden — this is exactly why the old check missed it.'
		);
	}
}

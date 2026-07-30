<?php
/**
 * Profile-field visibility is a CUMULATIVE hierarchy, not four independent gates.
 *
 * The spec order is private > connections > followers > members > public, and
 * ProfileService::visibility_rank() already encodes it — but it was only ever used
 * to work out how restrictive a FIELD is (group vs field vs entry), never to work
 * out what the VIEWER is entitled to. The read path asked three separate questions
 * instead:
 *
 *     if ( 'connections' === $vis && ! $viewer_is_connection ) continue;
 *     if ( 'followers'  === $vis && ! $viewer_is_follower )   continue;
 *     if ( 'members'    === $vis && ! $viewer_is_member )     continue;
 *
 * Each tier checked only its own flag, so standing higher in the hierarchy did not
 * grant what is below it. A confirmed connection who does not also happen to press
 * Follow was refused followers-level fields — the closest relationship on the
 * platform seeing less than a looser one.
 *
 * These tests pin the hierarchy from both ends: each tier sees everything at or
 * below it, and still sees nothing above it.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\ProfileService;

/**
 * @covers \BuddyNext\Profile\ProfileService::get_profile
 */
class VisibilityHierarchyTest extends \WP_UnitTestCase {

	private ProfileService $service;

	/**
	 * Profile owner.
	 */
	private int $owner;

	/**
	 * Field keys by the visibility they were created at.
	 *
	 * @var array<string,string>
	 */
	private array $keys = array();

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new ProfileService();
		$this->owner   = self::factory()->user->create();

		// One field per tier, so a single get_profile() answers the whole hierarchy.
		foreach ( array( 'public', 'members', 'followers', 'connections' ) as $vis ) {
			$key                 = 'vis_' . $vis;
			$this->keys[ $vis ]  = $key;
			$this->service->create_field(
				array(
					'group_name'  => 'vis_group',
					'field_key'   => $key,
					'label'       => 'Field ' . $vis,
					'type'        => 'text',
					'visibility'  => $vis,
				)
			);
		}

		$this->service->save_profile(
			$this->owner,
			array(
				$this->keys['public']      => 'Public Value',
				$this->keys['members']     => 'Members Value',
				$this->keys['followers']   => 'Followers Value',
				$this->keys['connections'] => 'Connections Value',
			)
		);
	}

	/**
	 * Which of the four tier fields a viewer can actually read.
	 *
	 * @param int $viewer_id Viewer, or 0 for the anonymous public web.
	 * @return array<int,string> Visibility tiers the viewer can see, sorted.
	 */
	private function visible_tiers( int $viewer_id ): array {
		wp_cache_flush();
		$profile = $this->service->get_profile( $this->owner, $viewer_id );

		$seen = array();
		foreach ( (array) ( $profile['groups'] ?? array() ) as $group ) {
			foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
				$key = (string) ( $field['field_key'] ?? '' );
				$tier = array_search( $key, $this->keys, true );
				if ( false !== $tier ) {
					$seen[] = (string) $tier;
				}
			}
		}

		sort( $seen );
		return $seen;
	}

	/**
	 * A confirmed connection sees followers-level fields.
	 *
	 * This is the reported bug. `connections` outranks `followers`, so the closest
	 * relationship the platform has must not see less than a follower does.
	 */
	public function test_connection_sees_followers_level_fields(): void {
		$connection = self::factory()->user->create();
		$this->connect( $this->owner, $connection );

		$tiers = $this->visible_tiers( $connection );

		$this->assertContains( 'followers', $tiers, 'a connection must inherit followers-level access' );
		$this->assertContains( 'connections', $tiers );
		$this->assertContains( 'members', $tiers );
		$this->assertContains( 'public', $tiers );
	}

	/**
	 * A follower sees members + public, and NOT connections.
	 *
	 * The other half of the hierarchy: inheriting downwards must not become
	 * inheriting upwards.
	 */
	public function test_follower_sees_down_the_hierarchy_but_not_up(): void {
		$follower = self::factory()->user->create();
		$followed = buddynext_service( 'follows' )->follow( $follower, $this->owner );
		$this->assertNotWPError( $followed, 'follow fixture failed' );

		$tiers = $this->visible_tiers( $follower );

		$this->assertContains( 'followers', $tiers );
		$this->assertContains( 'members', $tiers );
		$this->assertContains( 'public', $tiers );
		$this->assertNotContains( 'connections', $tiers, 'a follower is not a connection' );
	}

	/**
	 * A logged-in stranger sees members + public only.
	 */
	public function test_plain_member_sees_members_and_public_only(): void {
		$stranger = self::factory()->user->create();

		$tiers = $this->visible_tiers( $stranger );

		$this->assertSame( array( 'members', 'public' ), $tiers );
	}

	/**
	 * The anonymous public web sees only public.
	 */
	public function test_anonymous_sees_public_only(): void {
		$this->assertSame( array( 'public' ), $this->visible_tiers( 0 ) );
	}

	/**
	 * The owner sees every tier regardless of relationship flags.
	 */
	public function test_owner_sees_everything(): void {
		$tiers = $this->visible_tiers( $this->owner );

		foreach ( array( 'public', 'members', 'followers', 'connections' ) as $vis ) {
			$this->assertContains( $vis, $tiers, "owner must see the {$vis} field" );
		}
	}

	/**
	 * A VIRTUAL field (registered through the `buddynext_profile_fields` filter) is
	 * gated by the same hierarchy as a stored one.
	 *
	 * merge_virtual_fields() carries its own copy of the visibility gate, and that
	 * copy had no test at all: with the gate replaced by `if ( false )` - letting
	 * every viewer read every virtual field regardless of visibility - the entire
	 * 237-test Profile suite still passed. A privacy check nothing can fail is not a
	 * check, and this is the path an integration's fields arrive on, so it is exactly
	 * where a silent leak would be least noticed.
	 *
	 * @return void
	 */
	public function test_virtual_field_respects_the_same_hierarchy(): void {
		add_filter(
			'buddynext_profile_fields',
			static function ( array $groups ): array {
				$groups[] = array(
					'group_key' => 'vis_group',
					'fields'    => array(
						array(
							'key'        => 'virtual_followers_only',
							'label'      => 'Virtual Followers Field',
							'type'       => 'text',
							'visibility' => 'followers',
							'value'      => 'Virtual Followers Value',
						),
					),
				);
				return $groups;
			}
		);

		$has_virtual = function ( int $viewer_id ): bool {
			wp_cache_flush();
			$profile = $this->service->get_profile( $this->owner, $viewer_id );
			foreach ( (array) ( $profile['groups'] ?? array() ) as $group ) {
				foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
					if ( 'virtual_followers_only' === ( $field['field_key'] ?? '' ) ) {
						return true;
					}
				}
			}
			return false;
		};

		// A logged-in stranger is below `followers` and must not see it.
		$stranger = self::factory()->user->create();
		$this->assertFalse( $has_virtual( $stranger ), 'a plain member must not read a followers-tier virtual field' );

		// The anonymous web certainly must not.
		$this->assertFalse( $has_virtual( 0 ), 'the public web must not read a followers-tier virtual field' );

		// A follower is at the tier and must see it.
		$follower = self::factory()->user->create();
		$followed = buddynext_service( 'follows' )->follow( $follower, $this->owner );
		$this->assertNotWPError( $followed, 'follow fixture failed' );
		$this->assertTrue( $has_virtual( $follower ), 'a follower must read a followers-tier virtual field' );

		// A connection is ABOVE the tier and must inherit it - the same hierarchy bug
		// existed on this path, not only on the stored-field path.
		$connection = self::factory()->user->create();
		$this->connect( $this->owner, $connection );
		$this->assertTrue( $has_virtual( $connection ), 'a connection must inherit a followers-tier virtual field' );
	}

	/**
	 * Make two users confirmed connections through the real service, so the test
	 * exercises the same state the read path queries rather than a hand-written row.
	 *
	 * @param int $a First user.
	 * @param int $b Second user.
	 * @return void
	 */
	private function connect( int $a, int $b ): void {
		$connections = buddynext_service( 'connections' );

		$sent = $connections->send_request( $a, $b );
		$this->assertNotWPError( $sent, 'connection request fixture failed, so the assertion below would prove nothing' );

		// accept_request( recipient, requester ) — recipient first. $b received it.
		$accepted = $connections->accept_request( $b, $a );
		$this->assertNotWPError( $accepted, 'connection accept fixture failed' );

		$this->assertTrue(
			$connections->are_connected( $a, $b ),
			'fixture did not produce a confirmed connection'
		);
	}
}

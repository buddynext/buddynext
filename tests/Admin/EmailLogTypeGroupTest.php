<?php
/**
 * Every email the site can send lands in a named group of the Email Log's
 * Type dropdown, never "Other" (card 10365309660).
 *
 * The groups match keywords, so partner and future types group without a list
 * to keep in step. This walks the template catalogue, so a new email type that
 * no keyword covers fails here instead of quietly falling into "Other".
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\EmailEditor;
use BuddyNext\Admin\EmailLog;

/**
 * @group admin
 */
class EmailLogTypeGroupTest extends \WP_UnitTestCase {

	public function test_every_catalogue_email_has_a_named_group(): void {
		$group = new \ReflectionMethod( EmailLog::class, 'type_group' );
		$log   = new EmailLog();
		$types = array( 'registration' ); // Sent outside the catalogue (account registration).
		foreach ( ( new EmailEditor() )->get_catalogue() as $category ) {
			$types = array_merge( $types, array_keys( (array) $category ) );
		}

		$other = array();
		foreach ( $types as $type ) {
			if ( __( 'Other', 'buddynext' ) === $group->invoke( $log, (string) $type ) ) {
				$other[] = $type;
			}
		}

		$this->assertSame( array(), $other, 'These email types fall into "Other": ' . implode( ', ', $other ) );
	}

	public function test_space_invites_stay_under_spaces(): void {
		$group = new \ReflectionMethod( EmailLog::class, 'type_group' );
		$this->assertSame( __( 'Spaces', 'buddynext' ), $group->invoke( new EmailLog(), 'bn.space_invite' ) );
		$this->assertSame( __( 'Account', 'buddynext' ), $group->invoke( new EmailLog(), 'bn.bulk_invite' ) );
	}
}

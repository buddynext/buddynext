<?php
/**
 * The one identity tone palette.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\AvatarService;
use WP_UnitTestCase;

/**
 * The palette had been copied into five places and three copies had drifted off
 * the design tokens entirely, so their colours silently did nothing while the
 * code read as if it worked.
 */
class IdentityToneTest extends WP_UnitTestCase {

	/**
	 * Every tone the helper can return is one the CSS actually defines.
	 *
	 * This is the assertion that would have caught the original bug:
	 * MembersSidebarProvider cycled eight tokens of which the stylesheet defined
	 * none, so every letter avatar fell back to the default hue.
	 *
	 * @return void
	 */
	public function test_every_tone_is_defined_in_css(): void {
		$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/bn-base.css' );

		$this->assertIsString( $css );

		foreach ( AvatarService::IDENTITY_TONES as $tone ) {
			$this->assertStringContainsString(
				'.bn-avatar[data-tone="' . $tone . '"]',
				$css,
				sprintf( 'Identity tone "%s" has no rule in bn-base.css, so it renders as the default.', $tone )
			);
		}
	}

	/**
	 * No semantic or integration-accent token may be used for identity. `danger`
	 * is the destructive red and `warn` the caution amber; colouring a member's
	 * initials with them says something about the member that is not true.
	 *
	 * @return void
	 */
	public function test_no_semantic_or_integration_token_is_used_for_identity(): void {
		$forbidden = array( 'accent', 'success', 'warn', 'danger', 'info', 'jetonomy', 'media', 'events', 'paid' );

		foreach ( $forbidden as $token ) {
			$this->assertNotContains(
				$token,
				AvatarService::IDENTITY_TONES,
				sprintf( '"%s" carries meaning (status or integration) and must not be an identity colour.', $token )
			);
		}
	}

	/**
	 * The excluded purple/pink family stays excluded — two templates had been
	 * cycling `violet` and `rose`.
	 *
	 * @return void
	 */
	public function test_the_excluded_colour_family_stays_excluded(): void {
		foreach ( array( 'violet', 'rose', 'purple', 'pink', 'indigo' ) as $token ) {
			$this->assertNotContains( $token, AvatarService::IDENTITY_TONES );
		}
	}

	/**
	 * Same entity, same colour, every render.
	 *
	 * @return void
	 */
	public function test_the_tone_is_stable_for_an_entity(): void {
		$this->assertSame(
			AvatarService::identity_tone_for( 7 ),
			AvatarService::identity_tone_for( 7 )
		);
	}

	/**
	 * Different entities spread across the palette rather than clustering.
	 *
	 * @return void
	 */
	public function test_tones_spread_across_the_palette(): void {
		$seen = array();
		foreach ( range( 1, 60 ) as $id ) {
			$seen[ AvatarService::identity_tone_for( $id ) ] = true;
		}

		$this->assertCount( count( AvatarService::IDENTITY_TONES ), $seen );
	}

	/**
	 * A negative id cannot produce a negative array offset.
	 *
	 * @return void
	 */
	public function test_a_negative_id_is_safe(): void {
		$this->assertContains( AvatarService::identity_tone_for( -5 ), AvatarService::IDENTITY_TONES );
	}
}

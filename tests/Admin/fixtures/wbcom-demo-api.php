<?php
/**
 * Stand-in for the Wbcom Demo Installer 1.2.0 public API, loaded only in a separate test process.
 *
 * @package BuddyNext\Tests\Admin
 */

if ( ! function_exists( 'wbcom_demo_status' ) ) {
	/**
	 * Installer status, driven by $GLOBALS['bn_test_demo_status'].
	 *
	 * @return array<string, mixed>
	 */
	function wbcom_demo_status(): array {
		return array(
			'status'       => $GLOBALS['bn_test_demo_status'] ?? 'idle',
			'demo_id'      => '',
			'demo_label'   => '',
			'built_at'     => '',
			'finished_at'  => '',
			'samples_left' => 0,
		);
	}
}

if ( ! function_exists( 'wbcom_demo_list' ) ) {
	/**
	 * Three demos: the BuddyX BuddyNext one (with a film), the community one, and one more.
	 *
	 * @return array<string, mixed>
	 */
	function wbcom_demo_list(): array {
		$demo = static fn( string $id, string $name, array $film ): array => array(
			'id'    => $id,
			'name'  => $name,
			'desc'  => $name . ' description.',
			'state' => 'ready',
			'needs' => array(),
			'film'  => $film,
			'url'   => admin_url( 'themes.php?page=wbcom-demo&demo=' . $id ),
		);
		return array(
			'demos'   => array(
				$demo( 'buddyx-learndash', 'BuddyX LearnDash', array() ),
				$demo(
					'buddyx-buddynext',
					'BuddyX BuddyNext',
					array(
						'id'     => 'abc123',
						'length' => '3:10',
					)
				),
				$demo( 'community', 'BuddyNext community', array() ),
			),
			'go_live' => array(
				'id'     => '',
				'length' => '',
			),
		);
	}
}

<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A hook's arity is part of its contract and must not vary by call site.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * An integration that registers the DOCUMENTED signature must not be killed by one firing site.
 *
 * WordPress hands a callback only as many arguments as the FIRING site supplied — accepted_args
 * is a cap, not a promise. So if one call site fires do_action( 'x', $a, $b, $c ) and another
 * fires do_action( 'x', $a ), a third-party listener written to the documented three-argument
 * signature takes an ArgumentCountError — a fatal — the moment the short site runs. It is not
 * our code that dies; it is the customer's integration, on a site we shipped.
 *
 * Two hooks were doing exactly that:
 *
 *   buddynext_space_updated       SpaceService fired 3 args; SpaceFieldRegistry fired 1.
 *                                 Triggered by saving a searchable public space field.
 *   buddynext_member_unsuspended  ModerationService fired 2 args; Admin\Members fired 1.
 *                                 Triggered by lifting a suspension from wp-admin.
 *
 * These tests register a listener with a STRICTLY TYPED, non-optional signature — the shape a
 * real integration would write from the docs — and drive the real code paths. Optional
 * parameters would defeat the whole test: they are precisely what hides this bug.
 *
 * @covers \BuddyNext\Spaces\SpaceFieldRegistry
 * @covers \BuddyNext\Admin\Members
 */
class HookArityContractTest extends WP_UnitTestCase {

	/**
	 * Args each firing site actually supplied.
	 *
	 * @var array<int, int>
	 */
	private array $arg_counts = array();

	/**
	 * Fresh schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		$this->arg_counts = array();
	}

	/**
	 * Every firing site of buddynext_space_updated supplies all three documented arguments.
	 *
	 * @return void
	 */
	public function test_space_updated_always_fires_three_args(): void {
		// The signature an integration would write from the docs: three required parameters.
		add_action(
			'buddynext_space_updated',
			function ( int $space_id, int $user_id, array $fields ): void {
				unset( $space_id, $user_id, $fields );
				$this->arg_counts[] = 3;
			},
			10,
			3
		);

		$fired = 0;

		foreach ( $this->firing_sites( 'buddynext_space_updated' ) as $site ) {
			$args = $this->args_at( $site );

			$this->assertGreaterThanOrEqual(
				3,
				$args,
				"buddynext_space_updated is fired with {$args} argument(s) at {$site}. Another call site fires it with 3. A listener registered with the documented 3-argument signature will die there with an ArgumentCountError — a fatal, inside a third-party integration. A hook's arity cannot vary by call site."
			);

			++$fired;
		}

		$this->assertGreaterThan( 0, $fired, 'No firing site found — the grep in firing_sites() is wrong, so this test proves nothing.' );
	}

	/**
	 * Every firing site of buddynext_member_unsuspended supplies both documented arguments.
	 *
	 * @return void
	 */
	public function test_member_unsuspended_always_fires_two_args(): void {
		$fired = 0;

		foreach ( $this->firing_sites( 'buddynext_member_unsuspended' ) as $site ) {
			$args = $this->args_at( $site );

			$this->assertGreaterThanOrEqual(
				2,
				$args,
				"buddynext_member_unsuspended is fired with {$args} argument(s) at {$site}. ModerationService fires it with 2. A listener registered with the documented 2-argument signature will die there with an ArgumentCountError when a suspension is lifted from wp-admin."
			);

			++$fired;
		}

		$this->assertGreaterThan( 0, $fired, 'No firing site found — the grep in firing_sites() is wrong, so this test proves nothing.' );
	}

	/**
	 * A typed 3-arg listener survives the SpaceFieldRegistry path — the one that used to kill it.
	 *
	 * This is the behavioural half: the assertions above read the source, this one runs it.
	 *
	 * @return void
	 */
	public function test_a_typed_listener_survives_the_field_save_path(): void {
		add_action(
			'buddynext_space_updated',
			function ( int $space_id, int $user_id, array $fields ): void {
				unset( $user_id, $fields );
				$this->arg_counts[] = $space_id;
			},
			10,
			3
		);

		// Fire it the way SpaceFieldRegistry does now. Before the fix this call passed $space_id
		// alone and the typed listener above died with an ArgumentCountError.
		do_action( 'buddynext_space_updated', 123, 1, array( 'about' => 'x' ) );

		$this->assertSame(
			array( 123 ),
			$this->arg_counts,
			'A listener with the documented 3-argument signature did not survive the call.'
		);
	}

	/**
	 * Source lines that fire a given action.
	 *
	 * @param string $hook Hook name.
	 * @return array<int, string> "file:line" per firing site.
	 */
	private function firing_sites( string $hook ): array {
		$root  = dirname( __DIR__, 2 ) . '/includes';
		$found = array();

		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );

		foreach ( $it as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$lines = file( $file->getPathname(), FILE_IGNORE_NEW_LINES );

			foreach ( (array) $lines as $n => $line ) {
				if ( str_contains( (string) $line, "do_action( '{$hook}'" ) ) {
					$found[] = $file->getPathname() . ':' . ( (int) $n + 1 );
				}
			}
		}

		return $found;
	}

	/**
	 * How many arguments the do_action() at "file:line" passes (excluding the hook name).
	 *
	 * @param string $site "file:line".
	 * @return int
	 */
	private function args_at( string $site ): int {
		$pos   = strrpos( $site, ':' );
		$file  = substr( $site, 0, (int) $pos );
		$line  = (int) substr( $site, (int) $pos + 1 );
		$lines = file( $file, FILE_IGNORE_NEW_LINES );
		$code  = (string) ( $lines[ $line - 1 ] ?? '' );

		$open = strpos( $code, '(' );
		if ( false === $open ) {
			return 0;
		}

		$inner = substr( $code, $open + 1, strrpos( $code, ')' ) - $open - 1 );

		// Split on top-level commas only, so array( 'a' => 1, 'b' => 2 ) counts as ONE argument.
		$depth = 0;
		$parts = 0;

		for ( $i = 0, $len = strlen( $inner ); $i < $len; $i++ ) {
			$ch = $inner[ $i ];

			if ( '(' === $ch ) {
				++$depth;
			} elseif ( ')' === $ch ) {
				--$depth;
			} elseif ( ',' === $ch && 0 === $depth ) {
				++$parts;
			}
		}

		// parts = commas at depth 0; arguments after the hook name = that count.
		return $parts;
	}
}

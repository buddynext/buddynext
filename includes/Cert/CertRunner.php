<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Functional-certification engine.
 *
 * The deterministic core of the "Certify" system (docs/plans/
 * functional-certification-system.md). It asserts that the plugin actually
 * BEHAVES — not that its code is well-formed — by exercising the live runtime:
 *
 *   contract : for every gated setting, flip it OFF then ON and prove the
 *              REST surface's behaviour changes (the disabled error code appears
 *              when off, is absent when on). Catches "dead toggle" bugs — a
 *              control that saves but enforces nothing.
 *   boot     : dispatch every public/GET REST route and assert no route 500s /
 *              fatals. Catches handler regressions that static analysis cannot.
 *
 * The contract oracle is the one hand-authored input (audit/cert-oracles.json);
 * the route list and the set of toggleable features are DERIVED from
 * audit/manifest.json + FeatureRegistry, so coverage tracks the product and any
 * uncovered feature surfaces as a HOLE rather than as silent green.
 *
 * Runs inside WordPress (WP-CLI) so it has the DB, the hooks, and internal REST
 * dispatch available. Plugin-agnostic in spirit: only the oracle file and the
 * FeatureRegistry hook are BuddyNext-specific; see the plan for the shared
 * `wbcom/certify` extraction.
 *
 * @package BuddyNext\Cert
 */

declare( strict_types=1 );

namespace BuddyNext\Cert;

/**
 * Loads the manifest + oracles and runs the functional checks, producing a
 * coverage ledger.
 */
class CertRunner {

	/**
	 * Absolute plugin directory (trailing slash).
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Decoded manifest, or empty array when absent.
	 *
	 * @var array<string,mixed>
	 */
	private array $manifest;

	/**
	 * Decoded oracle file, or empty array when absent.
	 *
	 * @var array<string,mixed>
	 */
	private array $oracles;

	/**
	 * Locate and load the manifest + oracle files for this plugin.
	 *
	 * @param string|null $dir Plugin dir; defaults to BUDDYNEXT_DIR / two levels up.
	 */
	public function __construct( ?string $dir = null ) {
		if ( null === $dir ) {
			$dir = defined( 'BUDDYNEXT_DIR' ) ? (string) constant( 'BUDDYNEXT_DIR' ) : dirname( __DIR__, 2 ) . '/';
		}
		$this->dir      = trailingslashit( $dir );
		$this->manifest = $this->read_json( $this->dir . 'audit/manifest.json' );
		$this->oracles  = $this->read_json( $this->dir . 'audit/cert-oracles.json' );
	}

	/**
	 * Run the requested checks and return the full ledger.
	 *
	 * @param string[] $checks Subset of {'contract','boot'}; empty = all.
	 * @return array{summary:array<string,int>,rows:array<int,array<string,mixed>>,ok:bool}
	 */
	public function run( array $checks = array() ): array {
		$checks = empty( $checks ) ? array( 'contract', 'boot' ) : $checks;
		$rows   = array();

		// Authenticate as the primary admin so permission_callback gates pass and
		// the FEATURE gate (which runs after auth) is what we actually probe.
		$prev_user = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		if ( function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( 1 );
		}

		if ( in_array( 'contract', $checks, true ) ) {
			$rows = array_merge( $rows, $this->contract() );
		}
		if ( in_array( 'boot', $checks, true ) ) {
			$rows = array_merge( $rows, $this->boot() );
		}

		if ( function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( $prev_user );
		}

		$summary = array(
			'pass' => 0,
			'fail' => 0,
			'hole' => 0,
		);
		foreach ( $rows as $r ) {
			$status = (string) ( $r['status'] ?? 'hole' );
			if ( isset( $summary[ $status ] ) ) {
				++$summary[ $status ];
			}
		}
		$ok = ( 0 === $summary['fail'] );

		$this->write_ledger( $summary, $rows, $ok );

		return array(
			'summary' => $summary,
			'rows'    => $rows,
			'ok'      => $ok,
		);
	}

	/**
	 * Contract check — behaviour-flip every oracle, HOLE every uncovered feature.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function contract(): array {
		$rows    = array();
		$oracles = isset( $this->oracles['contract'] ) && is_array( $this->oracles['contract'] ) ? $this->oracles['contract'] : array();
		$covered = array();

		foreach ( $oracles as $o ) {
			$id       = (string) ( $o['id'] ?? '' );
			$kind     = (string) ( $o['kind'] ?? 'feature' );
			$off_code = (string) ( $o['off_code'] ?? '' );
			$route    = (string) ( $o['route'] ?? '' );
			$method   = (string) ( $o['method'] ?? 'POST' );
			$params   = isset( $o['params'] ) && is_array( $o['params'] ) ? $o['params'] : array();
			if ( '' === $id || '' === $route || '' === $off_code ) {
				continue;
			}
			$covered[ $id ] = true;

			$snapshot = $this->snapshot( $id, $kind );
			$this->set_state( $id, $kind, false );
			$off = $this->dispatch( $route, $method, $params );
			$this->set_state( $id, $kind, true );
			$on = $this->dispatch( $route, $method, $params );
			$this->restore( $id, $kind, $snapshot );

			$off_enforced = ( $off['code'] === $off_code );        // disabled code present when OFF.
			$on_allows    = ( $on['code'] !== $off_code );          // disabled code absent when ON.
			$pass         = $off_enforced && $on_allows;

			$rows[] = array(
				'check'  => 'contract',
				'entity' => $id,
				'status' => $pass ? 'pass' : 'fail',
				'detail' => sprintf(
					'off→%s(%d) on→%s(%d) expect off=%s',
					'' === $off['code'] ? '-' : $off['code'],
					$off['status'],
					'' === $on['code'] ? '-' : $on['code'],
					$on['status'],
					$off_code
				),
			);
		}

		// Coverage-honest: every toggleable feature WITHOUT an oracle is a HOLE.
		foreach ( $this->toggleable_features() as $slug ) {
			if ( isset( $covered[ $slug ] ) ) {
				continue;
			}
			$rows[] = array(
				'check'  => 'contract',
				'entity' => $slug,
				'status' => 'hole',
				'detail' => 'no oracle — enforcement unproven (add via `cert learn`)',
			);
		}

		return $rows;
	}

	/**
	 * Boot check — dispatch every GET REST route; fail any that 500s / throws.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function boot(): array {
		$rows = array();
		// Manifest v3 (scanner) stores routes under features.restRoutes with a separate
		// namespace; the legacy onboard format used rest.endpoints with the full route.
		// Support both so cert keeps working across the manifest migration.
		$endpoints = array();
		if ( isset( $this->manifest['features']['restRoutes'] ) && is_array( $this->manifest['features']['restRoutes'] ) ) {
			foreach ( $this->manifest['features']['restRoutes'] as $r ) {
				$ns    = isset( $r['namespace'] ) ? trim( (string) $r['namespace'], '/' ) : '';
				$route = isset( $r['route'] ) ? '/' . ltrim( (string) $r['route'], '/' ) : '';
				$endpoints[] = array(
					'methods' => isset( $r['methods'] ) ? $r['methods'] : array(),
					'route'   => '/' . $ns . $route,
				);
			}
		} elseif ( isset( $this->manifest['rest']['endpoints'] ) && is_array( $this->manifest['rest']['endpoints'] ) ) {
			$endpoints = $this->manifest['rest']['endpoints'];
		}

		foreach ( $endpoints as $e ) {
			$methods = isset( $e['methods'] ) && is_array( $e['methods'] ) ? $e['methods'] : array();
			$route   = (string) ( $e['route'] ?? '' );
			if ( '' === $route || ! in_array( 'GET', $methods, true ) ) {
				continue;
			}
			$res = $this->dispatch( $route, 'GET', array() );
			// >=500 (or a thrown fatal captured as 500) is a boot failure; 4xx is
			// fine — a route rejecting bad/missing params is working as designed.
			$pass   = ( $res['status'] < 500 );
			$rows[] = array(
				'check'  => 'boot',
				'entity' => 'GET ' . $route,
				'status' => $pass ? 'pass' : 'fail',
				'detail' => sprintf( 'status=%d%s', $res['status'], '' !== $res['code'] ? ' code=' . $res['code'] : '' ),
			);
		}

		return $rows;
	}

	// ── runtime helpers ────────────────────────────────────────────────────────

	/**
	 * Internal REST dispatch. Substitutes a literal "1" for any regex path param
	 * so concrete routes resolve. Captures throwables as a synthetic 500 so one
	 * bad route is reported, not fatal to the whole run.
	 *
	 * @param string              $route  Route pattern from the manifest.
	 * @param string              $method HTTP verb.
	 * @param array<string,mixed> $params Request params.
	 * @return array{status:int,code:string}
	 */
	private function dispatch( string $route, string $method, array $params ): array {
		$route = (string) preg_replace( '/\(\?P<[^>]+>[^)]*\)/', '1', $route );
		try {
			$req = new \WP_REST_Request( $method, $route );
			foreach ( $params as $k => $v ) {
				$req->set_param( $k, $v );
			}
			$res    = rest_do_request( $req );
			$status = (int) $res->get_status();
			$data   = $res->get_data();
			$code   = ( is_array( $data ) && isset( $data['code'] ) ) ? (string) $data['code'] : '';
			return array(
				'status' => $status,
				'code'   => $code,
			);
		} catch ( \Throwable $t ) {
			return array(
				'status' => 500,
				'code'   => 'throwable:' . $t->getMessage(),
			);
		}
	}

	/**
	 * Snapshot the raw stored value for a feature flag or option so it can be
	 * restored exactly (including the "never set" case).
	 *
	 * @param string $id   Flag slug or option name.
	 * @param string $kind 'feature' | 'option'.
	 * @return mixed Sentinel-tagged snapshot.
	 */
	private function snapshot( string $id, string $kind ) {
		$name = ( 'feature' === $kind ) ? 'buddynext_features' : $id;
		// get_option returns false when unset; we tag explicitly to distinguish a
		// stored false from "absent".
		$raw = get_option( $name, '__cert_absent__' );
		return $raw;
	}

	/**
	 * Set a flag/option to on or off without disturbing sibling flags.
	 *
	 * @param string $id   Flag slug or option name.
	 * @param string $kind 'feature' | 'option'.
	 * @param bool   $on   Desired state.
	 * @return void
	 */
	private function set_state( string $id, string $kind, bool $on ): void {
		if ( 'feature' === $kind ) {
			$opt = get_option( 'buddynext_features', array() );
			if ( ! is_array( $opt ) ) {
				$opt = array();
			}
			$opt[ $id ] = $on;
			update_option( 'buddynext_features', $opt, false );
			return;
		}
		// Store '0'/'1' — NOT bool. update_option($name,false) makes
		// get_option($name,true) return the *default* true (WP cannot tell stored
		// false from absent), so a boolean-false would never read as "off" through
		// the guards' get_option('...',true). '0'/'1' is also exactly how the admin
		// toggle persists these (see the hidden-0 pattern in Settings).
		update_option( $id, $on ? '1' : '0', false );
	}

	/**
	 * Restore a snapshot captured by snapshot().
	 *
	 * @param string $id       Flag slug or option name.
	 * @param string $kind     'feature' | 'option'.
	 * @param mixed  $snapshot Value from snapshot().
	 * @return void
	 */
	private function restore( string $id, string $kind, $snapshot ): void {
		$name = ( 'feature' === $kind ) ? 'buddynext_features' : $id;
		if ( '__cert_absent__' === $snapshot ) {
			delete_option( $name );
			return;
		}
		update_option( $name, $snapshot, false );
	}

	/**
	 * The toggleable (non-mandatory) feature slugs, for HOLE detection.
	 *
	 * @return string[]
	 */
	private function toggleable_features(): array {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return array();
		}
		$features = buddynext_service( 'features' );
		if ( ! is_object( $features ) || ! method_exists( $features, 'catalog' ) ) {
			return array();
		}
		$out = array();
		foreach ( $features->catalog() as $slug => $feature ) {
			$tier = (string) ( $feature['tier'] ?? '' );
			if ( 'mandatory' === $tier ) {
				continue; // Mandatory features have no toggle to enforce.
			}
			$out[] = (string) $slug;
		}
		return $out;
	}

	/**
	 * Read + decode a JSON file, returning an array (empty on any failure).
	 *
	 * @param string $path Absolute path.
	 * @return array<string,mixed>
	 */
	private function read_json( string $path ): array {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return array();
		}
		$decoded = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local dev tooling, not a runtime path.
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Persist the ledger so CI / the MCP can read the machine-readable verdict
	 * and so coverage regressions can be diff-gated over time.
	 *
	 * @param array<string,int>              $summary Pass/fail/hole counts.
	 * @param array<int,array<string,mixed>> $rows   Ledger rows.
	 * @param bool                           $ok      Overall pass.
	 * @return void
	 */
	private function write_ledger( array $summary, array $rows, bool $ok ): void {
		$ledger = array(
			'ok'      => $ok,
			'summary' => $summary,
			'rows'    => $rows,
		);
		$path   = $this->dir . 'audit/cert-ledger.json';
		if ( is_writable( dirname( $path ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- local dev/CI tooling, never a runtime web path.
			file_put_contents( $path, (string) wp_json_encode( $ledger, JSON_PRETTY_PRINT ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local dev tooling, not a runtime path.
		}
	}
}

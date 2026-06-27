<?php
/**
 * BuddyNext — Integration registry.
 *
 * The single, OPEN list of integrations a site owner can control. Every
 * integration — our in-house bridges AND any third-party plugin — registers an
 * entry on the public `buddynext_integrations` filter (only when its plugin is
 * active). The Integrations admin page and the `buddynext_integration_enabled()`
 * gate are both derived from this registry, so adding the Nth integration needs
 * ZERO core edits — the same dogfood seam the Nav API uses.
 *
 * An integration declares: a short stable key, a label, whether it contributes
 * nav and/or activity, and (for aggregating surfaces like the Pro Portfolio) its
 * sub-tabs. The owner's per-integration / per-sub-tab toggles are read through
 * the helper, never the raw option, so keys never drift.
 *
 * @package BuddyNext\Integrations
 */

declare( strict_types=1 );

namespace BuddyNext\Integrations;

/**
 * Process-wide registry of owner-controllable integrations.
 */
final class IntegrationRegistry {

	/**
	 * Shared singleton.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Resolved entries, keyed by integration key. Null until first build.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	private ?array $resolved = null;

	/**
	 * Shared instance.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * The registered integrations, keyed + normalized. Built once per request: the
	 * public filter fires lazily so a bridge can register against the live request.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function all(): array {
		if ( null !== $this->resolved ) {
			return $this->resolved;
		}

		/**
		 * Register owner-controllable integrations.
		 *
		 * Each integration (in-house or third-party) adds ONE entry keyed by a short
		 * stable slug. Register only when the integration's plugin is active.
		 *
		 * @param array<string,array<string,mixed>> $items Entries keyed by integration key.
		 */
		$raw = (array) apply_filters( 'buddynext_integrations', array() );

		$out = array();
		foreach ( $raw as $key => $entry ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_array( $entry ) || isset( $out[ $key ] ) ) {
				continue;
			}
			$subtabs = array();
			foreach ( (array) ( $entry['subtabs'] ?? array() ) as $sub_key => $sub_label ) {
				$sub_key = sanitize_key( (string) $sub_key );
				if ( '' !== $sub_key ) {
					$subtabs[ $sub_key ] = (string) $sub_label;
				}
			}
			$out[ $key ] = array(
				'key'      => $key,
				'label'    => isset( $entry['label'] ) && '' !== (string) $entry['label'] ? (string) $entry['label'] : ucfirst( $key ),
				'has_nav'  => ! empty( $entry['has_nav'] ),
				'has_feed' => ! empty( $entry['has_feed'] ),
				'subtabs'  => $subtabs,
			);
		}

		$this->resolved = $out;
		return $out;
	}

	/**
	 * A single integration entry, or null when not registered.
	 *
	 * @param string $key Integration key.
	 * @return array<string,mixed>|null
	 */
	public function get( string $key ): ?array {
		$all = $this->all();
		return $all[ sanitize_key( $key ) ] ?? null;
	}

	/**
	 * Reset the memoized list. Test-only convenience.
	 */
	public function reset(): void {
		$this->resolved = null;
	}
}

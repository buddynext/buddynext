<?php
/**
 * Addon module: bell notifications through the integration contract.
 *
 * @package BuddyNext\Addons
 */

declare( strict_types=1 );

namespace BuddyNext\Addons;

/**
 * An addon that speaks the Wbcom notification contract on behalf of a partner.
 *
 * The partner (or the addon) fires `notification_hook()` with the contract
 * payload as the LAST argument; BuddyNext stores the bell row, groups it, and
 * lets push and realtime pick it up. Bell only unless a type sets `email`.
 *
 * @api
 * @since 1.2.7
 */
interface NotificationSource {

	/**
	 * The action that carries the payload as its last argument.
	 *
	 * @return string
	 */
	public function notification_hook(): string;

	/**
	 * Prefix of the `_community_notification_*` hooks (a-z, 0-9, _).
	 *
	 * @return string
	 */
	public function notification_prefix(): string;

	/**
	 * Lucide icon slug shown on the module's bell rows.
	 *
	 * @return string
	 */
	public function notification_icon(): string;

	/**
	 * The types this source sends.
	 *
	 * Keyed by slug: label, description, default_on, email, audience.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function notification_types(): array;

	/**
	 * Whether the viewer may still see each row's object.
	 *
	 * @param array<int|string,bool>         $visible   Row key => true, to refine.
	 * @param int                            $viewer_id Viewer.
	 * @param array<int|string,array<mixed>> $targets   Row key => the object each row points at.
	 * @return array<int|string,bool>
	 */
	public function notification_visible( array $visible, int $viewer_id, array $targets ): array;
}

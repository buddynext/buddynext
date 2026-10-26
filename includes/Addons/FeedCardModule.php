<?php
/**
 * Addon module: typed cards in the activity feed and on Explore.
 *
 * @package BuddyNext\Addons
 */

declare( strict_types=1 );

namespace BuddyNext\Addons;

/**
 * An addon that posts typed cards (a course, an order, a badge) to the feed.
 *
 * Declare the card types once; BuddyNext registers the post type, the label and
 * icon the feed and Explore both show, and the card renderer. Publish, withdraw and
 * restore through {@see \BuddyNext\Feed\IntegrationActivity}, after checking
 * {@see AbstractAddon::feed_enabled()}.
 *
 * @api
 * @since 1.2.7
 */
interface FeedCardModule {

	/**
	 * The card types this module owns.
	 *
	 * Keyed by post type (a-z, 0-9, _). `render` (optional) returns the card body
	 * HTML for `array $args` (the post-body args); leave it out for the uniform
	 * integration card. `preview` (optional) shapes the Explore preview meta.
	 *
	 * @return array<string,array{label:string,icon:string,render?:callable,preview?:callable}>
	 */
	public function card_types(): array;
}

# Pro and Integration Hooks

This page covers the hooks that cross plugin boundaries: the actions and filters BuddyNext Pro emits, the integration seams BuddyNext exposes to companion plugins (WPMediaVerse, Jetonomy, the real-time transport), and the PWA seams. It is for developers extending Pro, building a companion plugin, or wiring a gamification/CRM layer onto the free/pro contract.

![The Pro admin settings whose cross-plugin Pro and integration hooks are documented here](../images/admin-general.webp)

![The Platform Add-ons admin tab companion plugins extend through the integration seams on this page](../images/admin-integrations.webp)

For the core free hook surface (post, reaction, comment, space, moderation events) see the Core Hooks reference. This page is the layer above it.

## The free/pro contract: the "consumed by" column

The table below carries a **consumed by** column for every hook Pro fires. It names which of the two BuddyNext plugins actually attaches a listener, and it is the cross-plugin contract:

- **`buddynext`, `buddynext-pro`** - the hook is part of the live free-to-Pro wiring. Removing or renaming it breaks a real listener in the paired plugin. Treat it as a frozen contract.
- **`buddynext-pro`** - Pro fires it and Pro consumes it, internal to the Pro layer, but it is still a public seam you may hook.
- **(none)** - Pro fires it and nothing in the pair listens. It exists as an extension seam for your code or a companion plugin (gamification, CRM, analytics). These are safe, stable hooks; "none" means no first-party consumer, not private.

This is what lets a third party such as wb-gamification know which events are guaranteed to fire. Confirm the argument list against the call site before you hook - grep the hook name in the Pro plugin's `includes/`.

> **Note:** the column describes first-party listeners only, meaning the two BuddyNext plugins. Your own `add_action()` / `add_filter()` callbacks never appear there, so "none" is the normal state for a clean extension point.

## Pro-emitted hooks with their free<->pro mapping

The table lists every hook Pro fires. Names are exact.

| Hook | Type | Fired when | Parameters | consumed_by |
|---|---|---|---|---|
| `buddynext_ability_granted` | action | A Stripe `customer.subscription.created`/`.updated`/`invoice.paid` event resolves to an active or trialing subscription. Also fired by Free's access webhook, and by every membership grant bridge (WooCommerce, Paid Memberships Pro, and any third-party source). | `int $user_id, string $ability, string $source` - Pro's Stripe path omits the third argument; the access webhook and the grant bridges supply it. **`$source` is honoured only when declared** - see the note below. | `buddynext`, `buddynext-pro` |
| `buddynext_ability_revoked` | action | A Stripe `customer.subscription.deleted` event (or expiry) removes a plan ability. Also fired by Free's access webhook, and by every membership grant bridge. | `int $user_id, string $ability` | `buddynext` |
| `buddynextpro_stripe_subscription_synced` | action | After any Stripe subscription event has been synced into Pro state (created, updated, deleted, invoice). | `int $user_id, string $tier_slug, array $event` | (none) |
| `buddynext_pro_subscription_created` | action | A `bn_subscriptions` row is created (the canonical "user became a paying customer" event). | `int $sub_id, int $user_id, int $tier_id, string $source` | (none) |
| `buddynext_pro_subscription_expired` | action | A subscription lapses (daily expiry cron or webhook). | `int $sub_id, int $user_id, int $tier_id` | (none) |
| `buddynext_reaction_added` | action | A reaction is added (Pro re-fires through `SignalsCollector` for AI ranking). | `string $object_type, int $object_id, int $user_id, string $emoji` | `buddynext`, `buddynext-pro` |
| `buddynext_comment_created` | action | A comment is created (consumed by Pro's signal collector, realtime dispatcher, analytics). | `int $comment_id, string $object_type, int $object_id, int $user_id` | `buddynext`, `buddynext-pro` |
| `buddynext_user_followed` | action | A follow relationship is created (AI affinity + analytics signal). | `int $follower_id, int $following_id` | `buddynext`, `buddynext-pro` |
| `buddynext_post_created` | action | A post is created, including when a scheduled post is published. | `int $post_id, int $user_id, string $type` | `buddynext`, `buddynext-pro` |
| `buddynext_pro_ai_reply_generated` | action | An AI smart-reply suggestion request succeeds. | `int $user_id, int $post_id, int $suggestion_count` | (none) |
| `buddynext_pro_label_assigned` | action | A custom member label is assigned to a user. | `int $user_id, int $label_id, int $assigned_by_id` | (none) |
| `buddynext_pro_label_unassigned` | action | A custom member label is removed from a user. | `int $user_id, int $label_id` | (none) |
| `buddynext_pro_broadcast_dispatched` | action | A broadcast campaign is sent. | `int $broadcast_id, int[] $user_ids` | (none) |
| `buddynext_pro_bulk_action_executed` | action | A moderator runs a Pro bulk moderation operation. | `string $action, int[] $ids, int $actor_id, array $summary` | (none) |
| `buddynext_pro_loaded` | action | End of Pro's `Plugin::init()` - the Pro equivalent of Free's `buddynext_loaded`, for binding vertical modules. | (none) | (none) |
| `buddynext_pro_bind_services` | action | During Pro service-container binding, for registering custom service bindings. | `object $container` | (none) |
| `buddynext_profile_field_render` | filter | A Pro advanced profile field type is rendered. | `string $html, string $type, array $field, mixed $value, int $user_id` | `buddynext-pro` |
| `buddynext_search_query_args` | filter | Pro injects advanced search filter args before the SQL is built. | `array $args, string $query, int $viewer_id` | `buddynext`, `buddynext-pro` |
| `buddynextpro_stripe_webhook_skipped` | action | A Stripe subscription event was accepted but not acted on. Fired from `WebhookController::skip()`, so attaching an existing book of subscriptions can be audited rather than guessed at. | `string $reason, string $reference, array $context` - `$reason` is one of `no_matching_user`, `no_tier_slug`, `unknown_tier_slug`, `create_failed`, `subscription_paused`, `unhandled_status`; `$reference` is the Stripe subscription id; `$context` is reason-specific detail. | (none) |
| `buddynextpro_subscription_renewal_upcoming` | action | A membership is about to auto-renew, one firing per configured offset. | `int $subscription_id, int $user_id, int $tier_id, string $expires_at, int $days_left` | `buddynext-pro` |
| `buddynextpro_subscription_expiring_soon` | action | A membership is about to **end** rather than renew. Separate from the hook above because the member needs a different message. | `int $subscription_id, int $user_id, int $tier_id, string $expires_at, int $days_left` | `buddynext-pro` |
| `buddynextpro_invoice_partially_refunded` | action | Part of an order is refunded. A partial refund adjusts the price and leaves the member's plan alone; only a full refund ends access, and that path fires the revoke hooks instead. | `int $invoice_id, int $user_id, int $plan_id, float $amount` | (none) |
| `buddynextpro_renewal_reminder_offsets` | filter | The day offsets at which a reminder fires, per subscription. Note the same string is **also an option name** (`RenewalReminderService::OPT_OFFSETS`) that the owner sets in the admin, default `30,7,1`; the filter runs afterwards and can vary the offsets per row. | `int[] $offsets, array $row` | (none) |
| `buddynextpro_renewal_reminder_batch` | filter | How many subscriptions one reminder sweep processes. Default 500, floored at 1. Raise it on a large community whose sweep is not keeping pace. | `int $batch` | (none) |
| `buddynext_adopt_discussion_space` | filter | Before provisioning a new Jetonomy space for a BuddyNext space, asking whether an existing forum should be adopted instead. Asked rather than assumed, because this side cannot know why a space already exists | `int $forum_id, int $space_id, array $space` | `buddynext-pro` |
| `buddynext_space_discussion_provisioned` | action | A discussion was provisioned for a BuddyNext space - the other half of the adopt guard above | `int $space_id, int $forum_id` | `buddynext-pro` |
| `buddynext_onboarding_steps` | filter | The onboarding wizard's step list. Two things are supported: **append** an add-on step (this is how Pro inserts the membership-plan step), and **remove** any of the three optional core steps - `interests`, `spaces`, `people`. Profile and Notifications are identity and delivery choices and stay; removing every step falls back to the core list. Reordering is not supported (each section's markup and save handler pair by position). Entries missing key/label/icon, or duplicating a key, are dropped. | `array<int, array{key,label,icon}> $steps` | `buddynext-pro` |
| `buddynext_setup_wizard_steps` | filter | The ADMIN setup wizard's step list (the sibling of `buddynext_onboarding_steps`), a keyed registry so an add-on can append, remove, or reorder steps without editing core. Each entry needs a `label` and a callable `render`, with an optional `save`; entries missing key/label, with a non-callable render, or a duplicate key are dropped. The last entry is the finish step. Progress is stored as the step KEY, so changing the list never corrupts an in-flight wizard | `array $steps` (key => `[label, render, save]`) | (none) |
| `buddynext_gamification_show_skip_toast` | filter | Whether to show a skip toast when gamification declines an award (cooldown, daily cap, weekly cap). Defaults to `false`: a member is not told an action they completed earned nothing | `bool $show, array $event, int $user_id, string $reason` | (none) |
| `buddynext_media_service` | filter | A WPMediaVerse container service is resolved. The single seam into the media boundary - returning an object here takes over resolution | `object\|null $resolved, string $key` | (none) |
| `buddynext_app_connect_schemes` | filter | The custom URL schemes the app-connect bridge may redirect to. **Every scheme here can receive an application password**, so add one only for an app you control | `string[] $schemes` | (none) |
| `buddynext_app_strings` | filter | The translated app strings, so a site can override or white-label specific keys without touching the shared catalogue | `array $out, string $locale, array $strings` | (none) |
| `buddynext_presence_stamped` | action | A member's presence timestamp is refreshed. Pro's WebSocket layer listens here to broadcast presence | `int $user_id` | (none) |
| `buddynext_pwa_shell_assets` | filter | The URLs precached as the offline shell. Keep the list small - every entry is downloaded on install, for every member | `string[] $shell` | (none) |
| `buddynext_pwa_worker_imports` | filter | Extra scripts the service worker loads with `importScripts()`, so a companion adds its offline logic to BuddyNext's worker instead of registering a second one. Same-origin URLs only (cross-origin entries are dropped); each import is wrapped so one broken script cannot stop the worker installing (1.2.1) | `string[] $imports` | (none) |
| `buddynext_pwa_asset_paths` | filter | The service worker decides which static files it caches. Only BuddyNext's own plugin directories by default; theme, other plugins and uploads stay with the browser's HTTP cache. A family plugin that ships offline assets via `buddynext_pwa_worker_imports` adds its directory here. Since 1.2.4. | `string[] $own_urls` (directory URLs, absolute or root-relative) |
| `buddynext_head_meta` | filter | A surface descriptor before BuddyNext renders its head meta. Return an empty array to suppress BuddyNext's head output for that surface entirely | `array $descriptor` | (none) |

> **Note:** `buddynext_ability_granted` is fired with two arguments by Pro's Stripe `WebhookController` and with three (the extra `$source`) by Free's `AccessWebhookController`. Always register your callback for the lowest arg count you need (`add_action( 'buddynext_ability_granted', $cb, 10, 2 )`) so it works regardless of which producer fires.

**Removing an optional onboarding step.** Drop the entry whose `key` you don't want; the wizard renumbers and the finish flow is unchanged:

```php
// Skip the "Follow people" step during onboarding.
add_filter( 'buddynext_onboarding_steps', function ( array $steps ): array {
	return array_values( array_filter(
		$steps,
		fn( $step ) => 'people' !== ( $step['key'] ?? '' )
	) );
} );
```

### Custom reactions

Pro's custom premium reactions do not get their own event. They flow through the standard `buddynext_reaction_added` action above. To distinguish a premium reaction, inspect `$emoji` against `CustomReactionsService::get_custom_reactions()`.

## Integration seam hooks (BuddyNext exposes)

These are filters and actions BuddyNext (Free) defines so companion plugins can plug in. They are the supply side of the contract - Pro and third parties hook them.

### Outbound webhooks

```php
// Maximum number of outbound webhook endpoints a site may register.
// Free returns 1; Pro's UnlimitedWebhooksIntegration returns PHP_INT_MAX.
apply_filters( 'buddynext_outbound_webhook_limit', int $limit )
// Default: 1
```

The webhook engine itself (`OutboundWebhookService`) lives in Free. Pro only lifts the cap through this filter - it does not duplicate the delivery code.

### Real-time transport

```php
// Filter the active real-time transport. Resolve via TransportFactory::current().
// Never instantiate a transport directly. The returned value must implement
// BuddyNext\Realtime\RealtimeTransport; a non-conforming return silently falls
// back to PollingTransport.
apply_filters( 'buddynext_realtime_transport', RealtimeTransport $transport )
// Default: new PollingTransport()  (clients poll via REST)
```

Free ships a polling transport (5s active poll). Pro returns a WebSocket-backed transport so events push to connected clients instantly:

```php
add_filter(
    'buddynext_realtime_transport',
    static fn() => new \BuddyNextPro\Realtime\WebSocketTransport( $config )
);
```

Pro's `RealtimeDispatcher` then fans the standard free events (`buddynext_post_created`, `buddynext_reaction_added`, `buddynext_comment_created`, `buddynext_notification_created`, `mvs_message_sent`) out to Soketi channels.

### White-label

```php
// Plugin brand name shown in the UI. Resolve via Plugin::brand_name().
apply_filters( 'buddynext_brand_name', string $name )
// Default: 'BuddyNext'

// Plugin brand logo URL shown in the UI. Resolve via Plugin::brand_logo_url().
apply_filters( 'buddynext_brand_logo_url', ?string $url )
// Default: null
```

### WPMediaVerse seams (mvs_* hooks BuddyNext uses)

Direct messaging runs on WPMediaVerse; BuddyNext is the UI layer over it. These filters live in WPMediaVerse and BuddyNext hooks them:

```php
// BuddyNext returns true so WPMediaVerse suppresses its own chat panel + nav link.
apply_filters( 'mvs_buddynext_active', bool $active )

// BuddyNext injects a bn_blocks check before a message can be sent.
apply_filters( 'mvs_can_send_message', bool $allowed, int $sender_id, int $recipient_id )

// BuddyNext Pro verifies WebSocket availability for real-time DM.
apply_filters( 'mvs_messaging_transport', object $transport )
```

WPMediaVerse fires these actions, which BuddyNext bridges into community surfaces:

```php
do_action( 'mvs_message_sent',     int $message_id, int $conversation_id, int $sender_id, array $recipient_ids )
do_action( 'mvs_media_uploaded',   int $media_id, array $file_data, int $user_id, string $media_type )
do_action( 'mvs_media_deleted',    int $media_id, int $author_id, string $permalink )
do_action( 'mvs_reaction_added',   int $media_id, int $user_id, string $emoji )
do_action( 'mvs_comment_created',  int $media_id, int $user_id, int $comment_id, string $content, string $source )
do_action( 'mvs_favorite_toggled', int $media_id, int $user_id, string $action ) // 'added' | 'removed'
do_action( 'mvs_mentions_created', int $media_id, array $mentioned_user_ids, string $context, int $comment_id )
```

Pro's `RealtimeDispatcher` consumes `mvs_message_sent` to push a `message.new` event to the `private-conv-{N}` channel.

### Jetonomy

Jetonomy (forums/discussions) integrates through the unified Nav API and general-purpose injection seams rather than a dedicated hook set:

```php
// Register a Discussions tab on the profile and space nav surfaces. Fires with
// the NavRegistry; call $registry->register( [...] ) to add tabs. This is the
// current profile/space tab seam - it replaced the removed
// buddynext_profile_extra_data and buddynext_space_tabs filters.
do_action( 'buddynext_register_nav', BuddyNext\Nav\NavRegistry $registry )

// Add a Discussions link to the left navigation rail.
apply_filters( 'buddynext_rail_items', array $items, string $hub )

// Pull related Jetonomy discussions into a hashtag feed (shared tag slug).
apply_filters( 'buddynext_hashtag_related_discussions', array $discussions, string $hashtag_slug )
```

Jetonomy discussions also surface in the search index and the Explore deck as type `discussion`. See the Core Hooks reference for the full signatures of these seams.

> **Note:** The `buddynext_profile_extra_data` and `buddynext_space_tabs` filters were removed. The profile-stat-row and space-tab injection they provided is now the unified Nav API (`buddynext_register_nav` + `NavRegistry::register()`), which drives both the profile and space Discussions tabs from one registry.

### PWA seams

```php
// Gate service-worker registration. Return false to disable the PWA without
// unhooking PwaService. Front-end only (skipped in wp-admin).
apply_filters( 'buddynext_pwa_register_sw', bool $emit )
// Default: true

// Customise the Web App Manifest array before it is served at
// /wp-json/buddynext/v1/pwa/manifest.
apply_filters( 'buddynext_pwa_manifest', array $manifest )
```

> **Note:** The manifest filter is `buddynext_pwa_manifest` (it filters the whole manifest array). There is no separate `buddynext_pwa_register_manifest` hook - the manifest link tag is emitted on `wp_head` unconditionally and shaped through `buddynext_pwa_manifest`.

`PwaService` registers three public REST routes under `buddynext/v1` (all `permission_callback => '__return_true'`, served regardless of login state):

| Method | Path | Purpose |
|---|---|---|
| GET | `/pwa/manifest` | The Web App Manifest JSON, shaped by `buddynext_pwa_manifest` above. |
| GET | `/pwa/sw` | The service-worker script. Served through REST (not a static file) so it can be generated and cache-busted per install. |
| GET | `/pwa/offline` | The offline fallback page the service worker serves when a navigation fails with no cached match. Precached without a version query so the shell's bare URLs are guaranteed a cache hit. |

There is deliberately no `/pwa/icon` route - manifest icons are served as real PNG files instead, because Chromium rejects REST-served SVG images in a manifest ("isn't a valid image").

```php
// Disable the PWA entirely.
add_filter( 'buddynext_pwa_register_sw', '__return_false' );

// Override the install prompt name and theme color.
add_filter( 'buddynext_pwa_manifest', function ( array $manifest ): array {
    $manifest['name']        = 'Acme Community';
    $manifest['short_name']  = 'Acme';
    $manifest['theme_color'] = '#1d4ed8';
    return $manifest;
} );
```

## More Pro-emitted hooks

Pro hooks that fire but were missing from the table above. Same columns. `consumed_by` lists first-party listeners found by `add_action()` / `add_filter()` calls with a literal hook name.

### Membership, subscriptions, and payments

| Hook | Type | Fired when | Parameters | consumed_by |
|---|---|---|---|---|
| `buddynextpro_purchase_completed` | action | A purchase completes at a gateway (Stripe, PayPal, Points, or the Test gateway). Fulfilment listens here to grant access and write the subscription. | `int $user_id, int $plan_id, string $gateway, array $extra` | `buddynext-pro` |
| `buddynextpro_subscription_activated` | action | A subscription has been activated and the invoice recorded. | `int $user_id, int $plan_id, string $gateway` | (none) |
| `buddynextpro_subscription_renewed` | action | A provider billing event renews a subscription. The fulfilment listener records the renewal invoice. | `int $sub_id, int $user_id, string $expires_at, array $context` | `buddynext-pro` |
| `buddynext_pro_subscription_cancelled` | action | A subscription is cancelled but keeps access until the period ends. | `int $sub_id, int $user_id, int $tier_id, string $expires_at` | `buddynext-pro` |
| `buddynext_pro_subscription_resumed` | action | A cancelled subscription is set to renew again. Undo whatever you did on the cancellation, such as a win-back email sequence. | `int $sub_id, int $user_id, int $tier_id, string $expires_at` | (none) |
| `buddynext_pro_subscription_past_due` | action | A subscription enters the past-due grace window. | `int $sub_id, int $user_id, int $tier_id, string $grace_until` | `buddynext-pro` |
| `buddynext_pro_subscription_extended` | action | An admin extends a subscription manually. | `int $sub_id, int $user_id, int $tier_id, int $days, string $new_expires` | (none) |
| `buddynext_pro_subscription_comped` | action | An owner extends a cancelled subscription, giving access that is never charged again. | `int $sub_id, int $user_id, int $tier_id` | (none) |
| `buddynextpro_plan_changed` | action | A member moves from one plan to another. | `int $user_id, int $from_tier_id, int $to_tier_id, array $quote` | `buddynext-pro` |
| `buddynextpro_invoice_refunded` | action | An invoice is refunded in full at the provider and marked locally. The matching plan has already ended. | `int $invoice_id, int $user_id, int $plan_id, float $amount, string $reason` | `buddynext-pro` |
| `buddynextpro_refund_recorded` | action | A provider-reported refund has been recorded. | `int $invoice_id, float $refunded, bool $is_full, string $reason` | (none) |
| `buddynextpro_gateway_cancel_failed` | action | A subscription could not be cancelled at its provider, so the member is still being billed. Alert the owner or retry. | `int $user_id, int $sub_id, string $gateway, string $external_id` | (none) |
| `buddynextpro_renewal_amount_mismatch` | action | A renewal charge is too small for its tier and is refused. Usually a provider subscription attached to the wrong plan. | `int $tier_id, float $paid, float $price, array $context` | (none) |
| `buddynextpro_renewal_amount_floor` | filter | The fraction of the tier price a renewal must reach (default `0.5`). | `float $floor, int $tier_id, array $context` | (none) |
| `buddynextpro_past_due_grace_days` | filter | The past-due grace period, in days. | `int $days, int $sub_id` | (none) |
| `buddynextpro_expiry_sweep_batch` | filter | Subscriptions processed per expiry sweep (default `500`). | `int $batch` | (none) |
| `buddynextpro_non_billing_subscription_sources` | filter | Subscription sources that carry no provider reference (default `manual`, `points`). Add a source that grants without charging. | `string[] $sources` | (none) |
| `buddynextpro_paypal_manage_url` | filter | The PayPal automatic-payments management link. | `string $url, int $user_id` | (none) |
| `bn_membership_onetime_is_lifetime` | filter | A one-time purchase is fulfilled. Return `true` to grant perpetual access instead of a term. | `bool $lifetime, array $tier, array $extra` | (none) |
| `buddynextpro_default_plan` | filter | The default plan for members with no paid subscription is resolved. Return a tier row, or `null` for no default plan. The result is re-validated. | `array\|null $plan` | (none) |
| `buddynextpro_paid_access_exempt` | filter | A member opens a paying-members-only community without a plan. Return `true` to exempt them. | `bool $exempt, int $user_id` | (none) |
| `buddynextpro_entitlement_resolve` | filter | An entitlement value is resolved for a user. | `mixed $value, int $user_id, string $key` | (none) |
| `buddynextpro_register_entitlements` | action | After all built-in entitlement keys are registered. Register your own with `EntitlementRegistry::register()`. | none | (none) |
| `buddynextpro_register_payment_gateways` | action | The gateway registry is built. Register a gateway with `GatewayRegistry::register()`. | none | `buddynext-pro` |
| `buddynextpro_tier_saved` | action | A plan is created or edited, so an integration can save its own plan-form fields. | `int $tier_id` | `buddynext-pro` |
| `buddynextpro_plan_form_sections` | action | The plan add/edit form renders extra sections. | `array $tier` (empty when adding) | `buddynext-pro` |
| `buddynextpro_payments_tab_sections` | action | After the Payments settings form, so a section that posts to its own endpoint is not nested inside the form. | none | `buddynext-pro` |
| `buddynextpro_content_is_protected` | filter | A WordPress post or page is checked for members-only gating. Return `true` to gate it. | `bool $is_protected, int $post_id` | (none) |
| `buddynextpro_content_can_view` | filter | A viewer is checked against protected content. | `bool $can_view, int $user_id` | (none) |
| `buddynextpro_content_locked_html` | filter | The locked-content card HTML is built. | `string $html, int $post_id` | (none) |
| `buddynextpro_paywall_context` | filter | The paywall context is resolved before render. | `array $context, int $space_id, string $tier_slug` | (none) |
| `buddynextpro_pricing_in_shell` | filter | The pricing page decides whether to render inside the BuddyNext shell (default `true`). | `bool $in_shell, int $page_id` | (none) |
| `buddynextpro_format_price` | filter | An amount is formatted for display. | `string $formatted, float $amount, string $currency, array $tier` | (none) |
| `buddynextpro_supported_currencies` | filter | The supported currency list (code => label). | `array $codes` | (none) |
| `buddynextpro_zero_decimal_currencies` | filter | The currencies billed in whole units. | `string[] $codes` | (none) |
| `buddynextpro_three_decimal_currencies` | filter | The currencies billed in thousandths. | `string[] $codes` | (none) |
| `buddynextpro_woo_granting_statuses` | filter | The WooCommerce order statuses that grant a mapped plan (default `processing` and `completed`). | `string[] $statuses` | (none) |
| `buddynextpro_stripe_checkout_session_params` | filter | The Stripe Checkout Session parameters before the API call. | `array $params, array $args, int $user_id, int $plan_id` | (none) |
| `buddynextpro_stripe_create_checkout_session` | filter | Short-circuit the live Stripe call (for tests). Return an array with `redirect_url` and `session_id` to bypass it. | `mixed $result, array $params, int $user_id, int $plan_id` | (none) |
| `buddynextpro_stripe_construct_event` | filter | Short-circuit Stripe signature validation (for tests). Return an event array or `WP_Error` to be honoured. | `mixed $event, string $payload, string $signature, string $secret` | (none) |
| `buddynextpro_space_owner_stats_enabled` | filter | A space owner's "Last 30 days" analytics row is about to show. | `bool $enabled, int $space_id, int $viewer_id` | (none) |
| `buddynext_pro_learnomy_source_released` | action | A Learnomy source was deleted and its community was released. | `int $space_id, string $type, int $source_id` | (none) |
| `buddynext_pro_learnomy_backfill_chunk_size` | filter | Roster rows joined per Learnomy backfill run (floored at 1). | `int $size, string $type, int $source_id` | (none) |

### Email, analytics, AI, push, and platform

| Hook | Type | Fired when | Parameters | consumed_by |
|---|---|---|---|---|
| `buddynext_pro_broadcast_deleted` | action | A broadcast campaign and its recipients are deleted. | `int $campaign_id` | (none) |
| `buddynextpro_broadcast_dispatch_failed` | action | A broadcast dispatch resolved to nobody and was not sent. | `int $campaign_id, string $reason` | (none) |
| `buddynextpro_broadcast_batch_size` | filter | Recipients processed per broadcast run. | `int $batch_size` | (none) |
| `buddynextpro_drip_batch_size` | filter | Active drip enrollments scanned per tick (default `100`). | `int $limit` | (none) |
| `buddynext_drip_triggers` | filter | The drip auto-enrollment triggers (slug => `label`, `hook`). | `array $defaults` | (none) |
| `buddynextpro_segment_batch_size` | filter | Rows fetched per `WP_User_Query` page when a segment is resolved. | `int $batch` | (none) |
| `buddynextpro_analytics_rate_limit` | filter | Analytics events allowed per user per minute. `0` or a negative number disables throttling. | `int $limit, int $actor_id, string $event_type` | (none) |
| `buddynextpro_data_retention_purged` | action | After a retention prune of the analytics tables. | `array $deleted, int $retention` (rows removed per table, window in days) | (none) |
| `buddynextpro_admin_funnel_events` | filter | The ordered event keys that drive the funnel view. | `string[] $events` | (none) |
| `buddynextpro_funnel_event_query` | filter | The SQL count statement for a funnel event. | `string $sql, string $event, string $start, string $end` | (none) |
| `buddynextpro_funnel_event_actors` | filter | The SELECT of distinct actor ids for a funnel event, so a step can intersect the running cohort. Return `''` for the count-only path. | `string $sql, string $event, string $start, string $end` | (none) |
| `buddynextpro_profile_views_url` | filter | The "See all viewers" link in the profile views widget. Empty hides it. | `string $url` | (none) |
| `buddynextpro_ai_generate_text_pre` | filter | Before the core AI client is called. A non-null return skips it. | `string\|null $pre, string $prompt` | (none) |
| `buddynextpro_ai_client_error` | action | An AI text generation call failed. | `WP_Error\|Throwable $error, string $prompt` | (none) |
| `buddynextpro_ai_mod_actor` | filter | The user id automated AI moderation is attributed to. The id must hold `manage_options` or it is ignored. | `int $actor_id` | (none) |
| `buddynextpro_ai_mod_watched_tones` | filter | The classifier tones that trigger an automatic flag. | `string[] $tones` | (none) |
| `buddynextpro_default_mod_rules` | filter | The built-in default moderation rules. | `array $defaults` | (none) |
| `buddynextpro_embedding_async` | filter | Whether embedding writes are deferred to Action Scheduler (default `true`). | `bool $async` | (none) |
| `buddynextpro_push_async` | filter | Whether push delivery is deferred to Action Scheduler (default `true`). | `bool $async` | (none) |
| `buddynextpro_known_push_pref_types` | filter | The notification types Pro tracks push preferences for. | `string[] $types` | (none) |
| `buddynextpro_expo_access_token` | filter | The Expo push access token (default from the stored option). | `string $token` | (none) |
| `buddynextpro_realtime_config` | filter | The realtime configuration sent to the browser. | `array $config, int $user_id` | (none) |
| `buddynextpro_realtime_should_enqueue` | filter | Whether the realtime client is enqueued on this page. | `bool $enqueue, bool $on_hub` | `buddynext-pro` |
| `buddynextpro_advanced_field_surfaces` | filter | Stylesheet handles whose presence means an advanced profile field may be on the page. | `string[] $handles` | (none) |
| `buddynextpro_leaflet_css` / `buddynextpro_leaflet_js` | filter | The Leaflet asset URLs used by the map field. Point them at a self-hosted copy. | `string $url` | (none) |
| `buddynext_suite_panels_ttl` | filter | The cache lifetime of a member's suite panels, in seconds. | `int $ttl, int $member_id` | (none) |
| `buddynextpro_user_data_purged` | action | After Pro has purged a deleted user's per-user rows, so extensions can clean their own Pro tables. | `int $user_id` | (none) |

`buddynext_suite_panels_changed` is not fired by Pro. It is a listener-only seam: an integration that changes panel data outside a post type calls `do_action( 'buddynext_suite_panels_changed', $member_id )` to drop that member's cached panels, which Pro rebuilds on the next profile view.

## More Free integration seams

Hooks BuddyNext (Free) fires that sit at the boundary with apps, webhooks, and companion plugins.

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_webhook_auto_disabled` | action | An outbound webhook endpoint is auto-disabled after repeated delivery failures. | `int $webhook_id, string $url` |
| `buddynext_webhook_log_retention_days` | filter | The cron prune reads how long the outbound-webhook delivery log is kept (default `30`; `0` disables pruning). | `int $days` |
| `buddynext_require_signed_timestamp` | filter | The access webhook decides whether a request must carry a signed timestamp. Strict by default; a site with legacy senders can opt out per request. | `bool $strict, WP_REST_Request $request` |
| `buddynext_feature_{slug}` | filter | A feature's final on/off state is resolved. `{slug}` is the feature slug, for example `buddynext_feature_sidebar`. | `bool $enabled, array $feature` (state from the option and tier default, and the catalogue entry) |
| `buddynext_min_app_version` | filter | The app config reports the lowest app version this site serves (default `''`, no floor). | `string $version` |
| `buddynext_app_strings_version` | filter | The cache-bust version for the app's translated strings (default the newest translation file's mtime). | `int $version` |

## Example: provision access on `buddynext_ability_granted`

`buddynext_ability_granted` is the canonical "this user just gained an entitlement" event and the clearest illustration of the free<->pro contract. It is fired by two producers - Free's `AccessWebhookController` (an external CRM or payment platform POSTs to the access webhook) and Pro's Stripe `WebhookController` (a Stripe subscription went active) - and consumed by both plugins. Pro's `WebhookSubscriptionSync` listens for it to create a `bn_subscriptions` row whenever the ability matches the `tier:<slug>` convention.

Your own code hooks the same event to provision whatever the membership unlocks - a download, an LMS enrollment, a Slack invite - without caring which producer fired it:

```php
/**
 * Provision external access when a member gains a tier ability.
 *
 * Fires for BOTH the Stripe webhook (Pro) and the inbound access webhook (Free),
 * so a single listener covers every grant path. Register for 2 args - the Free
 * producer passes a third ($source) but you rarely need it.
 */
add_action(
    'buddynext_ability_granted',
    function ( int $user_id, string $ability ): void {
        // Tier grants follow the `tier:<slug>` convention.
        if ( 0 !== strncmp( $ability, 'tier:', 5 ) ) {
            return;
        }

        $tier_slug = substr( $ability, 5 );

        // Provision your own external access here.
        my_lms_enroll_user( $user_id, $tier_slug );
        my_crm_tag_customer( $user_id, 'tier-' . $tier_slug );
    },
    10,
    2
);
```

To reverse the provisioning when the entitlement is lost, hook the paired `buddynext_ability_revoked` action (also fired by both Stripe cancellation and the access webhook):

```php
add_action(
    'buddynext_ability_revoked',
    function ( int $user_id, string $ability ): void {
        if ( 0 === strncmp( $ability, 'tier:', 5 ) ) {
            my_lms_unenroll_user( $user_id, substr( $ability, 5 ) );
        }
    },
    10,
    2
);
```

## Branding the invoice (Pro)

The membership invoice (`templates/membership/invoice.php`) is a **standalone document**: it renders outside the app shell, with its own `<head>`, and loads none of the site's stylesheets. That is deliberate - it means an invoice prints the same on every theme. It also never follows dark mode, because its destination is a printer or a PDF and a dark invoice is a black page.

It is branded through filters rather than through settings fields. A business needs its company name, registered address, and VAT/GST number on an invoice, and every business needs a slightly different set - growing a settings screen one field at a time to chase that is how you end up with a settings maze. So the seams are filters, and if a field turns out to be near-universal we can promote it to an option later.

| Filter | Signature | What it controls |
|---|---|---|
| `buddynextpro_invoice_logo_url` | `string $url, array $invoice` | The mark at the top. Defaults to the Appearance logo (`buddynext_logo_url`) - the same setting the HTML emails use - and falls back to the seller name as a wordmark when no logo is set. |
| `buddynextpro_invoice_seller` | `array $seller` | `name`, `email`, `address`. |
| `buddynextpro_invoice_footer` | `string $html, array $invoice, array $seller` | The footer block. Accepts markup (`wp_kses_post`) - this is where VAT/GST numbers, company registration and payment terms go. |
| `buddynextpro_invoice_palette` | `array $palette, array $invoice` | `brand`, `page`, `surface`, `text`, `muted`, `border`, `paid_bg`, `paid_text`, `on_brand` (hex) plus `font` (a CSS font stack). `brand` is seeded from `buddynext_brand_color`. |
| `buddynextpro_invoice_title` | `string $title, array $invoice` | The document `<title>`. |

A colour that does not survive `sanitize_hex_color()` falls back to its default, so a bad value degrades the invoice rather than breaking it.

### Snippet: company details, registered address, and a VAT/GST number

Drop this in a site plugin (or your child theme's `functions.php`).

```php
// Who the invoice is FROM.
add_filter(
	'buddynextpro_invoice_seller',
	function ( array $seller ): array {
		$seller['name']    = 'Acme Communities Ltd.';
		$seller['email']   = 'billing@acme.example';
		$seller['address'] = "Unit 4, 12 Example Street\nBengaluru 560001\nIndia";

		return $seller;
	}
);

// The legal footer: registration number, tax id, payment terms.
add_filter(
	'buddynextpro_invoice_footer',
	function ( string $html, array $invoice, array $seller ): string {
		unset( $html, $invoice );

		return sprintf(
			'<p><strong>%s</strong></p><p>GSTIN: 29ABCDE1234F1Z5 &middot; CIN: U72900KA2020PTC000000</p><p>Payment due on receipt. Thank you for your business.</p>',
			esc_html( (string) $seller['name'] )
		);
	},
	10,
	3
);

// A print-specific logo (e.g. a dark mark that reads on white paper).
add_filter(
	'buddynextpro_invoice_logo_url',
	fn(): string => 'https://acme.example/brand/invoice-mark.png'
);
```

### Snippet: match the invoice to your brand

```php
add_filter(
	'buddynextpro_invoice_palette',
	function ( array $palette ): array {
		$palette['brand'] = '#0f766e';                       // accent (the Print button)
		$palette['text']  = '#111827';
		$palette['font']  = 'Georgia, "Times New Roman", serif';

		return $palette;
	}
);
```

## Notes and gotchas

- **Boot order.** Pro boots at `plugins_loaded:20` (Free at `:15`, bridges at `:25`). Register Pro-dependent listeners on `buddynext_pro_loaded`, not on an arbitrary `plugins_loaded` priority.
- **REST namespaces are separate.** Pro routes live under `buddynext-pro/v1`; Free under `buddynext/v1`. The PWA manifest and service worker are served from Free's `buddynext/v1` namespace.
- **An empty `consumed_by` is stable, not private.** Hooks like `buddynext_pro_subscription_created` and `buddynext_pro_broadcast_dispatched` have no first-party listener but are the documented contract for gamification/CRM integrations.
- **`buddynext_ability_granted` arg count differs by producer.** Free passes three args (`$source` last), Pro passes two. Register for two to stay compatible with both.

## Granting a membership from another system

`buddynext_ability_granted` is the contract a third-party membership system uses to say "this member now holds that plan". It is fired from three places: Pro's own Stripe handling, Free's HTTP access webhook, and every membership grant bridge.

**The `$source` argument is honoured only for a declared source.** `WebhookSubscriptionSync` writes the subscription row, and it accepts `$source` only when that slug has been declared through `buddynextpro_integration_subscription_sources`. An undeclared source is silently recorded as `manual`.

That failure is quiet and expensive. `manual` means "the owner comped this", so the member is told their membership was given to them rather than billed by your system, their Cancel and Manage controls resolve against the wrong place, and the revenue never appears as external billing. The grant works. Nothing errors.

If you are writing a WordPress plugin that grants BuddyNext plans, do not fire this action directly. Extend `BuddyNextPro\Bridges\AbstractGrantBridge`, which declares the source for you, keeps answering for rows it created after your plugin is deactivated, and gets reconciliation and dry-run support for free. See [Membership Grant Bridges](53-membership-grant-bridges.md).

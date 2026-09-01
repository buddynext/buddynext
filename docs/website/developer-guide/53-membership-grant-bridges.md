# Membership Grant Bridges

This page is for connecting a system that already sells memberships — WooCommerce, Paid Memberships Pro, MemberPress, an LMS, your own CRM — to BuddyNext plans. When a customer buys there, they get the matching BuddyNext plan here; when they stop paying, they lose it.

BuddyNext Pro ships bridges for WooCommerce and Paid Memberships Pro. This page documents the contract behind them so you can write your own, in your own plugin, without editing BuddyNext.

## What a bridge does and does not do

A bridge **mirrors**. It never charges, refunds, or cancels anything at the other system.

That division is the whole design. The system that took the money owns the money: it has the payment method, the invoice, the refund button and the legal relationship with the customer. BuddyNext owns access. A member with a WooCommerce-billed plan sees "Billed through WooCommerce, so it is managed there" with a link to their WooCommerce account, and BuddyNext's own Cancel and Change-plan controls do not render — because cancelling here could only take away access while WooCommerce carried on charging their card.

So a bridge does two things:

- listens to its partner and says "this member now holds that plan", or "no longer";
- answers three questions about itself: what it is called, where the member manages their billing, and which of its products or levels map to which BuddyNext plans.

## The shape

Extend `BuddyNextPro\Bridges\AbstractGrantBridge` and register it on one filter. That is the whole integration surface.

```php
use BuddyNextPro\Bridges\AbstractGrantBridge;

class AcmeCrmBridge extends AbstractGrantBridge {

    protected function source(): string {
        return 'acme-crm';           // stable forever; written to every row
    }

    protected function version_constant(): string {
        return 'ACME_CRM_VERSION';   // defined when your plugin loads
    }

    protected function label(): string {
        return 'Acme CRM';           // spelled the way you spell it
    }

    protected function manage_url( int $user_id ): string {
        return acme_crm_account_url( $user_id );  // '' if there is nowhere to send them
    }

    protected function listen(): void {
        add_action( 'acme_crm_membership_changed', array( $this, 'on_change' ), 20, 1 );
    }

    public function on_change( int $user_id ): void {
        $this->reconcile( $user_id );
    }

    public function reconcile( int $user_id ): void {
        $wanted = array();

        foreach ( acme_crm_active_products( $user_id ) as $product_id ) {
            $slug = $this->tier_for( $product_id );   // '' when unmapped

            if ( '' !== $slug ) {
                $wanted[] = $slug;
            }
        }

        // Grant what is missing, revoke what is no longer earned.
        // See "Reconcile, do not apply the delta" below.
    }
}

add_filter(
    'buddynextpro_membership_sources',
    static function ( array $bridges ): array {
        $bridges[] = new AcmeCrmBridge();

        return $bridges;
    }
);
```

No edit to BuddyNext Pro is required, and none is expected. First-party bridges register through the same filter, in the same way, so a third-party source is not a second-class one.

## Reconcile, do not apply the delta

This is the mistake worth spending a paragraph on, because every partner invites it.

A partner event tells you what *changed*. It does not tell you what the member is *entitled to*. Those are different, and acting on the first produces wrong access that nobody notices.

Two real examples from the bundled bridges:

**Paid Memberships Pro.** `pmpro_after_change_membership_level` fires once when a member switches from level 1 to level 2 — and PMPro has already removed level 1 by the time it runs. Nothing announces that removal. A bridge that granted `$level_id` and stopped would leave the member holding both plans in BuddyNext: paying for one, entitled to two.

**WooCommerce.** An order being refunded does not mean the customer has lost the plan. They may have bought the same product twice. Revoking on "an order was refunded" cuts off somebody who is still paid up on their other order.

So on any change, read everything the member currently holds at the partner, work out the full set of plans they should have, and make BuddyNext agree:

```php
$wanted = $this->mapped_slugs_for( $user_id );   // from the partner, now
$held   = $this->bridged_slugs_for( $user_id );  // what we granted, now

foreach ( array_diff( $wanted, $held ) as $slug ) {
    $this->grant( $user_id, $slug );
}

foreach ( array_diff( $held, $wanted ) as $slug ) {
    $this->revoke( $user_id, $slug );
}
```

This also makes `reconcile()` idempotent, which is **required**: partners fire their hooks more than once for one logical change, and BuddyNext sweeps every bridge nightly.

Two consequences worth knowing:

- **Revoke only what you granted.** Scope `$held` to your own `source()`. A member can hold a Stripe-billed BuddyNext plan and one of yours at the same time, and your partner has no opinion about the first. Revoking anything absent from your entitlement set would cancel a membership bought through BuddyNext's own checkout.
- **Many-to-one is safe.** Two products granting the same plan is fine, because `$wanted` is a de-duplicated set: losing one of them leaves the plan in `$wanted` and it is never revoked.

## What a bridge does NOT take over

A bridge maps a partner's product or level to a BuddyNext plan. That is the whole of it, and the
boundary is worth stating because the alternative looks helpful and is not.

**Your system keeps governing your content. BuddyNext keeps governing the community.**

Paid Memberships Pro has a content-protection rule engine; WooCommerce Memberships has its own.
Those keep working on WordPress posts and pages exactly as they did before the bridge existed, and
BuddyNext does not read them, defer to them, or switch itself off because they are present. What
the mapping buys you is the other half: the plan a member holds *here*, so **BuddyNext's own
features** — gated spaces, plan entitlements, anything behind `tier:<slug>` — can be locked behind
a level they bought *there*.

So `bn_members_only`, BuddyNext's own post-level gate, is unaffected by a bridge. If an owner sets
both it and a PMPro rule on one post, both apply and the most restrictive wins.

That is deliberate, and it was decided against the alternative. Having BuddyNext suppress its own
gate whenever a bridge is active would mean silently switching off a control the owner set, on
content BuddyNext does not own, because a different plugin happens to be installed. A gate that
disappears when you install something else is worse than two gates that each do their job.

**Do not add bridge awareness to content protection.** It reads like a missing feature and is not
one.

## Mapping products to plans

`tier_for( $partner_key )` returns the BuddyNext plan slug an owner has mapped a product or level to, or `''`.

`''` means **not our business**. An owner maps the two products that grant membership and leaves the other four hundred alone. Never fall back to a default plan — that turns every unrelated purchase in the shop into a membership.

The mapping is stored per bridge and read through `mapping()`. Where an owner *edits* it is your choice, and the bundled bridges both put it on the partner's own screen: the plan selector for a PMPro level lives on PMPro's level editor, and for a WooCommerce product on the product's General tab. An owner deciding that a product grants membership is thinking about that product, on the page where they price it — a separate BuddyNext screen is a second place to keep in sync and a second place to forget.

## What the base class does for you

These are not conveniences. Each one fails **silently** when a bridge author forgets it, which is why the base owns them and `init()` is `final`.

**It declares your source.** `WebhookSubscriptionSync` honours the `$source` on a grant only for a source declared through `buddynextpro_integration_subscription_sources`. An undeclared source is recorded as `manual` — the grant works, the member gets their plan, and the row says the owner comped it. The member is then told their membership was a gift, Cancel and Manage resolve against the wrong system, and the revenue never reads as external billing.

**It keeps answering after your plugin is deactivated.** The source declaration, display name and manage URL are registered whether or not your partner is loaded; only `listen()` is conditional. Subscriptions keep their `source` in the database forever, so withdrawing the declaration on deactivation would silently reclassify every member you ever granted into a comp — the panel telling them their membership was a gift while your system carried on charging them.

**It gives you a real dry run.** `grant()` and `revoke()` are `final`, so the reconciler can withhold and report writes without your bridge knowing anything about it. You do not implement `--dry-run`, and you cannot get it wrong.

## Grant and revoke

```php
$this->grant( $user_id, $tier_slug );
$this->revoke( $user_id, $tier_slug );
```

Both fire the same in-process contract the HTTP access webhook fires, so a bridge grant and a webhook grant land in exactly one place and cannot behave differently.

A grant also emails the member, and that is worth knowing before you wire one. The email
(`bn.subscription_granted`, "Membership added") deliberately follows your partner's own — because
your partner cannot send it. WooCommerce knows about an order and PMPro about a level; neither
knows this community exists, so without it the membership simply appears and the member finds it
only if they happen to look. It says the two things only BuddyNext can say — what they now have
*here*, and that billing questions belong *there* — and it links to the community, never to a
pricing page they cannot buy from.

It carries `{{source_label}}` from your `label()` and `{{manage_url}}` from your `manage_url()`,
so both come out of your bridge rather than being guessed. A site owner who finds it redundant
switches it off in Settings -> Notifications -> Email Templates; a member switches it off in their
own preferences. You do not need to send anything yourself, and you should not.

`grant()` takes **no expiry**, deliberately. Your system owns the billing period, and a date copied across at grant time is stale the moment you renew or cancel. The truth arrives as your next event, not as a guess made now — so grant on renewal and revoke on cancellation, which is what your own hooks are for.

## Reconciliation

Events get missed. The site was down when your webhook fired; the plugin was deactivated for an afternoon; your cron expired a batch of memberships while WordPress was not running. None of that announces itself, and the member simply keeps access they stopped paying for or loses access they are still paying for.

BuddyNext therefore re-derives the state rather than trusting the events:

```
wp buddynext-pro reconcile-memberships                      # dry run, changes nothing
wp buddynext-pro reconcile-memberships --execute
wp buddynext-pro reconcile-memberships --source=acme-crm --execute
wp buddynext-pro reconcile-memberships --user=42 --execute
```

It also runs nightly, and once more whenever the set of installed bridges changes — so a bridge switched back on after a week catches up immediately instead of waiting for the next sweep.

Two things you can do to make it work well for your source:

**Override `candidate_user_ids( $limit, $offset )`.** The default finds members who already hold a plan from your bridge. That catches entitlement outliving payment, but it is blind to the opposite — a membership granted at your system that never reached BuddyNext, because that member has no BuddyNext row to be found by. If you can enumerate your own members cheaply, include them; it is the difference between the sweep repairing a missed grant and never seeing it.

**Guard on your partner.** `reconcile()` must do nothing when `partner_active()` is false. With your plugin absent there is nothing to compare against, and an empty entitlement set reads as "this member holds nothing there" — which would revoke everything your bridge ever granted. BuddyNext makes the same check before sweeping you, but make it yourself too.

## Related hooks

| Hook | Type | What it is for |
|---|---|---|
| `buddynextpro_membership_sources` | filter | Declare your bridge. Return instances of `AbstractGrantBridge`. |
| `buddynextpro_integration_subscription_sources` | filter | Declare a source slug as a real integration. `AbstractGrantBridge` does this for you. |
| `buddynextpro_membership_source_label` | filter | The name shown to members. `AbstractGrantBridge` answers it from `label()`. |
| `buddynextpro_external_manage_url` | filter | Where a member manages billing. `AbstractGrantBridge` answers it from `manage_url()`. |
| `buddynext_ability_granted` | action | The grant contract. Prefer `grant()` — firing this directly skips the source declaration. |
| `buddynext_ability_revoked` | action | The revoke half. Prefer `revoke()`. |
| `buddynext_email_template_catalogue` | filter | Where the "Membership added" email becomes editable and switchable by the owner. Pro registers it; you do not need to. |

See also: [Pro and Integration Hooks](33-hooks-pro-and-integration.md) for the wider cross-plugin surface, and [REST and Webhooks](23-rest-webhooks.md) for the HTTP door — the same grant contract, for systems that cannot run PHP in your WordPress process.

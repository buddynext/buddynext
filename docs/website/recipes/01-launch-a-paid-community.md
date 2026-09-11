# Recipe: Launch a Paid Community

**Goal:** turn your community into a paid membership, where people subscribe to unlock spaces and content.
**You'll need:** BuddyNext Pro, and a Stripe account.
**Time:** about 30-45 minutes for a first plan.

This recipe stitches together the separate pieces - plans, payments, gating, renewals, and a moderation baseline - into one order of operations. Each step links to the full guide for that piece; come here for the sequence, go there for the detail.

## Before you start

- Install and activate **BuddyNext Pro** on top of the free plugin.
- Have a **Stripe account** ready (test mode is fine while you set up).
- Decide, roughly, what a paying member gets that a free member does not - one or two gated spaces, or premium posts, is plenty to launch with.

## Steps

1. **Create your first plan.** Give it a name, a price, and a billing period (monthly or yearly), and describe the perks. Start with a single plan; you can add tiers later.
   Full guide: [Membership Plans](../pro/01-membership-plans.md).

2. **Connect Stripe.** Enter your Stripe keys, confirm the gateway status badge turns green, and add the webhook in Stripe so access stays in sync when payments succeed, fail, or refund.
   Full guide: [Stripe Payments](../pro/03-stripe-payments.md) (Steps 1-2). For other gateways or tax, see [Payment Gateways](../pro/22-payment-gateways.md) and [Coupons and Tax](../pro/23-coupons-and-tax.md).

3. **Gate a space to the plan.** Pick the space paying members unlock and require your plan to enter it. Non-members see an invitation to subscribe instead of the feed.
   Full guide: [Gated Spaces](../pro/02-gated-spaces.md). To paywall individual posts instead of a whole space, see [Content Protection](../pro/04-content-protection.md).

4. **Turn on renewal reminders.** Email members before a subscription renews or when a payment fails, so access does not lapse by surprise.
   Full guide: [Renewal Reminders](../pro/26-renewal-reminders.md).

5. **Set a moderation baseline.** Before you invite anyone, make sure the report button and review queue are working, so a paying community stays healthy from day one.
   Full guide: [Moderation Queue](../moderation/02-moderation-queue.md).

## What your members see

Members browse your plans, subscribe through hosted Stripe checkout, and manage their own subscription from **Settings > Membership** - upgrade, cancel, or view payment history and invoices. See [Membership Plans](../pro/01-membership-plans.md) ("How it works, for members") and [Account Settings](../accounts-access/07-account-settings.md).

## Related

- [Run a Private Client Space](02-private-client-space.md) - the same gating, for one-to-one or team access
- [Grow with Email](05-grow-with-email.md) - win back lapsed members and onboard new ones
- [Membership Plans](../pro/01-membership-plans.md) - the full reference for plans, statuses, and subscriptions

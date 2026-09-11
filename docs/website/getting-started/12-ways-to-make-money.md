# Ways to Make Money

BuddyNext Pro gives you several ways to charge for a community, not just one. This page explains each model, when it fits, and how they combine, so you can choose a business model before you wire up the mechanics. Every model here needs BuddyNext Pro and a connected payment gateway; once that is in place, the models are just different ways of deciding what a payment unlocks.

New to the money features? Read [What BuddyNext Pro Adds](../pro/00-overview.md) first for the parts list, then come back here for the strategy.

## The models

### 1. Recurring membership (subscription)

The default model: members pay a recurring fee - monthly or yearly - for ongoing access. Revenue is predictable and compounds as you grow.

- Set it up with [Membership Plans](../pro/01-membership-plans.md) and take payment through [Stripe](../pro/03-stripe-payments.md) or another [gateway](../pro/22-payment-gateways.md).
- Best for: creators, professional networks, and any community whose value is continuous.

### 2. Tiered plans (good / better / best)

Offer two or three plans at different prices, each unlocking more - more spaces, more content, more perks. Members self-select, and you capture both the price-sensitive and the committed.

- Create several plans and decide what each unlocks; entitlements are per-plan. See [Membership Plans](../pro/01-membership-plans.md).
- Best for: communities with a clear "casual vs serious" split.

### 3. Gated spaces (charge for a room)

Instead of charging for the whole community, charge for entry to specific spaces - a premium space, a mastermind, a paid cohort - while the rest stays open. Great for adding paid value on top of a free community.

- Require a plan to enter a space with [Gated Spaces](../pro/02-gated-spaces.md).
- Best for: a free community with one or more premium rooms; agency or client spaces.

### 4. Paywalled content (sell a single thing)

Lock individual posts or pieces of content behind a plan, so you can sell one item - a guide, a recording, a template - without selling a whole space.

- Lock content with [Content Protection](../pro/04-content-protection.md).
- Best for: selling standout content à la carte alongside a subscription.

### 5. The levers: trials, coupons, and tax

Layer these onto any model above to convert and comply:

- **Free trials** start the subscription at the provider, so access begins immediately and billing starts when the trial ends. See [Stripe Payments](../pro/03-stripe-payments.md).
- **Coupons** run launch discounts and win-back offers; **tax** is applied flat at checkout. See [Coupons and Tax](../pro/23-coupons-and-tax.md).
- **Renewal reminders** recover revenue that would quietly lapse. See [Renewal Reminders](../pro/26-renewal-reminders.md).

## Which model should I use?

| If you want to... | Use |
|---|---|
| Charge for the whole community | Recurring membership (1) |
| Offer entry-level and premium prices | Tiered plans (2) |
| Keep a free community but sell premium rooms | Gated spaces (3) |
| Sell one post, guide, or recording | Paywalled content (4) |
| Run a launch discount or a trial | Coupons and trials (5) |

These are not exclusive - a common shape is a free community with a paid tier (1 + 2) that unlocks a couple of premium spaces (3), with a launch coupon (5).

## How the money reaches you

Whichever model you choose, the flow is the same: a member subscribes through hosted checkout, the gateway takes the payment, and BuddyNext keeps their access in sync as payments succeed, renew, fail, or refund. Members manage their own subscription - upgrade, cancel, see invoices - from **Settings > Membership**, so you are not fielding billing requests by hand. Set the mechanics up once by following [Launch a Paid Community](../recipes/01-launch-a-paid-community.md).

## Related

- [What BuddyNext Pro Adds](../pro/00-overview.md) - the full Pro parts list
- [Launch a Paid Community](../recipes/01-launch-a-paid-community.md) - the end-to-end setup recipe
- [Membership Plans](../pro/01-membership-plans.md) - the reference for plans, tiers, and subscriptions

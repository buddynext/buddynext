# Gating Spaces Behind a Membership

A gated space is a space only members on a particular tier can join. When someone without the right membership tries to enter, BuddyNext shows a paywall - a friendly upgrade prompt with your call to action - instead of letting them in.

![A members-only space home shown only after the visitor meets the required tier](../images/space-home.png)

![The Monetization Tiers admin tab where you choose the membership tier that gates a space](../images/admin-tiers.png)

> **Before you start:** Gated spaces come with BuddyNext Pro and build on Membership Tiers. You need BuddyNext Pro active and at least one membership tier set up before you can gate a space. To sell access through checkout you also need a payment gateway connected (see Membership Tiers).

## Why use it

Open spaces help members find each other. Gated spaces let you give paying members something special the rest of the community cannot get, which is one of the clearest reasons for someone to upgrade.

Use a gated space when you want:

- A members-only area - a mastermind, a paid course cohort, a VIP lounge - that only subscribers of a specific plan can enter.
- A reason to upgrade that members can see. The paywall on a locked space is the most direct nudge you have: a member who wants in is one click from your pricing page.
- One purchase that unlocks several premium spaces at once, by selling a plan that carries the Gated Space Access perk.

For the member, a gated space is a clear boundary: this is part of what your membership includes. For you, it is access control that looks after itself - once a space requires a tier, every join attempt is checked automatically, on the website and in any connected app, with no per-member work.

## How it works

### What gates a space

A space is gated when you tie it to a membership tier. Only members whose active plan is that tier can join it. For example, a space gated behind your Premium plan can only be joined by Premium members.

When a space is gated:

- A member on the matching tier (or on a plan that grants the Gated Space Access perk) can join normally.
- A member without it is blocked, and the join is never recorded.

The Gated Space Access perk is the all-access pass. A plan that grants it lets its subscribers into any gated space, whatever specific tier each space asks for. Use it when you want one plan to unlock everything, instead of matching each space to its own tier.

### What a blocked member sees

When a logged-in member who lacks access opens a gated space, BuddyNext shows the paywall in place of the space content: a heading ("This space is available to members only."), your description, and an upgrade button. The button either starts checkout (when a gateway price is linked to the required tier) or points to the call-to-action link you set. The same paywall content is also sent to connected apps when a join is declined, so a mobile or headless front end can show its own version.


## Setting it up (for owners)

Gating a space is a two-part setup: mark the space as gated, then configure the paywall prompt members see.

### Step 1: Mark the space as gated

Here is the honest caveat to plan for: BuddyNext does not yet have a point-and-click control to gate a space. There is no toggle on the space settings screen, and the Paywall tab lists spaces that are already gated rather than letting you gate one.

For now, tying a space to a tier is a configuration step set behind the scenes rather than from a button. Once a space is linked to the tier you want (for example, the Premium plan), everything else - the join check, the paywall, and the per-space settings row on the Paywall tab - works automatically.

> **Warning:** Until a point-and-click gating control ships, this step needs a small technical change to the space. If you are not comfortable making it yourself, ask your developer or host to link the space to your chosen tier. Once that is done, the space appears in the Paywall tab and the rest of the setup is point-and-click.

Two related limitations to know about:

- Gated spaces are not visually badged. A space does not show a lock icon or "members only" label in space directories or listings. The gate is enforced when someone tries to join, and the paywall appears when a blocked member opens the space, but there is no badge marking the space as gated from the outside.
- The Paywall tab only lists spaces that are already gated. It reads the link you set in Step 1; it does not set it.

### Step 2: Configure the paywall prompt

Open BuddyNext in wp-admin, go to the Monetization section, and choose the Paywall tab. Here you set the upgrade prompt members see when they hit a gated space.

#### Global defaults

These apply to every gated space unless a space overrides them.

| Setting | What it does | Default |
|---|---|---|
| CTA URL | Where the upgrade button points (for example your pricing page). Leave blank to hide the button. | (empty) |
| Button Label | The text on the upgrade button. | Become a Member |
| Description | The copy shown under the paywall heading. | (empty) |

#### Per-space overrides

Below the global defaults, the Paywall tab lists every gated space with the tier it requires and its own CTA URL, Button Label, and Description fields. Fill any of these to override the global default for that one space; leave them blank to inherit the global values. This lets you point each premium space at a different upgrade page or word its prompt differently while keeping one shared default for the rest.


> **Tip:** Set a sensible CTA URL and description in the global defaults first - usually a link to your pricing page. Then add a per-space override only where a particular space needs its own wording or destination.

## Good to know

- The gate works everywhere. Once a space is gated, the join check runs on the website and in any connected app alike. A blocked member is never added, even from an app.
- Access can come from a tier or the all-access perk. A member gets in if they are on the tier the space requires, or if their active plan grants the Gated Space Access perk.
- Losing the subscription re-locks the space. When a subscription expires or is revoked, access is removed and the member can no longer enter the gated space.
- The paywall handles half-finished setups gracefully. When no gateway price is linked to the required tier, the button uses your CTA URL. When neither a price nor a URL is set, the prompt shows a friendly "not configured" notice rather than failing - so a partial setup never breaks the page.
- No badge yet. Because gated spaces are not marked in directories, tell members which spaces are premium in your space description or pricing copy until a visible badge ships.

## Free vs Pro

Gating spaces behind a membership, the paywall prompt, and the per-space override settings are all BuddyNext Pro and depend on Membership Tiers. BuddyNext Free has open and request-to-join spaces but no membership-based gating.

Within Pro, linking a space to a tier is a behind-the-scenes step today, while the paywall prompt is fully managed from the admin Paywall tab. A point-and-click control to gate a space, and a visible badge on gated spaces, are planned but not part of the current release.

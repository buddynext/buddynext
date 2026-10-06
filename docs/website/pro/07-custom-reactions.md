# Custom Reactions

Custom Reactions let you add your own emoji to the six reactions that ship with BuddyNext Free. You pick an emoji, give it a label, and it appears in the reaction picker right alongside the built-in set.

![Members reacting to a post with the custom emoji from the reaction picker](../images/post-detail.webp)

![The Engagement Reactions admin tab where you add and manage custom reactions](../images/admin-reactions.webp)

> **Before you start:** Custom Reactions come with BuddyNext Pro. With Pro active, you add and manage them from the Reactions settings screen described below.


## Why use it

Reactions are how members respond to a post without writing a comment, and the default six (the standard set every community starts with) cover most of what people want to say. But every community has its own personality and its own moments. A study group reacts differently than a gaming guild or a customer forum.

Adding a couple of well-chosen reactions lets your members express the things that matter in your space. A "celebrate" emoji for milestone posts, a "lightbulb" for a clever idea, a "thank you" for help freely given. These small touches make the feed feel like it belongs to your community rather than a generic template. Because every custom reaction uses the same Microsoft Fluent emoji set as the defaults, the whole picker stays visually consistent. Members do not see a jarring mix of mismatched icon styles.

Reactions are owner-curated on purpose. You decide which emoji are available, so the picker stays short, scannable, and on-brand instead of turning into an endless emoji keyboard.

## How it works (for members)

Members do not configure anything. Once you add a custom reaction, it shows up automatically in the reaction picker on every post:

1. A member opens the reaction picker on a post.
2. The picker shows the enabled default reactions plus every custom reaction you have added, each rendered as a Fluent emoji with its label.
3. The member selects a reaction, and the count for that reaction increments. Selecting a different one switches their reaction.

Custom reactions behave exactly like the built-in ones for counting, switching, and removal. They are served through the same API the website uses, so any connected client sees the same custom set.

## Setting it up (for owners)

Open **BuddyNext** in wp-admin and go to **Engagement > Reactions**. The tab is switched on by the **Custom reactions** entry in the Features catalogue (on by default). The screen has three parts: the default reactions, your current custom reactions, and the form to add a new one.

### Add a reaction

1. In the **Add a reaction** section, choose an emoji from the picker grid. The grid shows every available Fluent emoji minus the six defaults and any you have already used.
2. The **Label** field auto-fills from the emoji name you picked. Edit it if you want a different display name (for example, label the "party-popper" emoji as "Celebrate").
3. Select **Add reaction**. The new reaction appears in the current custom reactions table and is live in the picker immediately.

### Manage existing reactions

The **Current custom reactions** table lists each reaction with its label, emoji, slug, and order. Each row gives you two controls:

- **Switching a reaction off.** This screen has no on/off toggle. You turn a custom reaction off, without deleting it, in the reaction palette under **Engagement > Social**. A reaction that is switched off shows **(off)** next to its label here, drops out of the picker, and keeps its definition and the reactions members already left. Switch it back on there to return it.
- **Order.** Up (↑) and down (↓) buttons that move the reaction earlier or later in the picker. The arrows are greyed out at the ends of the list.
- **Remove.** Deletes the reaction after a confirmation prompt. There is no edit-in-place - to rename a reaction or change its emoji, remove it and add it again.

### The default reactions

The **Default reactions** section shows the six emoji that ship with BuddyNext Free, dimmed if they are currently turned off. These are managed in BuddyNext Free, not on this screen. To enable or disable them, follow the link to **Engagement > Social**. Your custom reactions appear alongside whichever defaults are enabled.

### Settings reference

The Add a reaction form has these inputs:

| Setting | What it does | Default |
|---|---|---|
| Emoji | The Fluent emoji shown in the picker. Chosen from a grid of available emoji (defaults and already-used emoji are excluded). | None - you must pick one |
| Label | The display name shown next to the emoji in the picker. Max 80 characters. | Auto-fills from the chosen emoji's name |

> **Note:** The total number of reactions (the six Free defaults plus your custom ones) is capped at 20. That leaves room for up to 14 custom reactions. The cap keeps the picker scannable so members are not faced with a wall of choices.

## Good to know

- **The cap is enforced.** Once the combined total reaches 20, no more custom reactions are merged into the picker. The Add a reaction hint always shows your current count and the limit.
- **You pick from a built-in emoji set.** The picker offers the Microsoft Fluent emoji that ship with BuddyNext. If every available emoji is already in use, the form tells you so. There is no free-text emoji or image upload.
- **No duplicates.** You cannot add an emoji that is already a built-in reaction, and you cannot add the same custom emoji twice. The form blocks both with a clear message.
- **Label is required.** An empty label is rejected, and so is a label over 80 characters.
- **Switching off is reversible; removing is not.** Switching a reaction off in Engagement > Social takes it out of the picker but preserves its definition and the reactions members already left, so you can turn it back on later. Removing a reaction deletes it - the reactions members already left using it are deleted too, but other reactions are untouched.
- **Only admins can manage reactions.** Adding and removing custom reactions is limited to site administrators.

## Free vs Pro

The six default reactions, the reaction picker, and reaction counting are all part of BuddyNext Free. Enabling or disabling the defaults is also handled in Free under Engagement - Social.

Custom Reactions - adding emoji beyond the default six - is a Pro feature. Pro adds your custom reactions to Free's reaction list, so everything stays consistent across the website and any connected app.
> **Note:** If you have turned Memberships on **and** chosen a default plan, the picker is capped by each member's plan through the **Reactions Set Size** limit (6 on the shipped Free plan, 20 on the seeded Pro plan). A member on a plan limited to 6 sees only the six defaults. With Memberships off (the default), every member sees the full set. See [Membership Plans](01-membership-plans.md).

## Related

- [Reactions](../community/04-reactions.md) - the free reaction set this extends.
- [Membership Plans](01-membership-plans.md) - custom reactions can be a plan perk.

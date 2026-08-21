# What's New in 1.0.4

BuddyNext 1.0.4 is about making the community feel personal from the very first minute. Members now pick interests when they join, and everything - people suggestions, space suggestions, the For You feed - shapes itself around what they chose. Around that headline sits a deep quality release: full control over profile fields, a friendlier and more consistent admin, and BuddyNext Pro's new member billing area. This page is a plain-language tour of what changed and why it matters.

![The community activity feed](../images/community-activity-feed.webp)

> **Note:** BuddyNext free and BuddyNext Pro are released together. If you run both, update them at the same time so they stay in step.

## The headline: interests that personalize everything

When a new member signs up, the onboarding wizard now asks what they are interested in. The choices come from your own space categories, so the list always matches what your community is actually about - a fitness site offers fitness topics, a design community offers design topics.

Those picks are not decoration. From the first session:

- **People suggestions** lead with members who share interests.
- **Space suggestions** lead with spaces in those categories.
- **The For You feed** ranks posts from those spaces higher.
- **Explore** suggests popular spaces from those interests instead of only the newest ones.

Members can change their interests any time on their profile, where each interest is a chip that links straight to the matching spaces in the directory. You can see (but not edit) each member's picks from the admin - interests belong to the member.

There is nothing to configure: if you have space categories, you have interests.

## Profile fields, your way

The profile form is now fully yours:

- **Help text and placeholders.** Every field can carry an owner-written hint under its name and an example inside the input, shown on both the profile editor and the signup form.
- **Sections per member type.** A profile section can be limited to one member type - coaches get coach fields, students get student fields, and a restricted section never appears on the signup form.
- **Remove what you do not need.** The preset Social Links, Work Experience, and Education sections can be deleted when they do not fit your community.
- **Protected core fields.** Bio, headline, and location are protected from accidental deletion, because search and member cards depend on them.
- **Safe deletes.** Removing a field or group that holds member data shows exactly how many members are affected and asks you to type its name to confirm. The cleanup runs in small background batches so large sites stay responsive.
- **Required means required.** An empty required field is now rejected with a clear message instead of being silently accepted.

## Small features members will notice

- **My Spaces** - `/spaces/mine/` lists the spaces a member belongs to.
- **Live typing indicator** in direct messages.
- **Connection request rows** show the requester's headline and your mutual connections.
- **Space cards** show a sub-space count - counting only sub-spaces the viewer may see.
- **Member type descriptions** appear when choosing a type at signup.

## A calmer, clearer admin

The whole admin had a quality pass this cycle:

- Settings cards fill the panel on every screen with one consistent card style; inputs stay capped at a comfortable reading width.
- The left navigation shows real hierarchy - section labels with their screens nested beneath - and works properly on iPad.
- Logo fields use the WordPress media library with a preview and Remove button.
- **Integration Display** is one card per integration with clear switches: navigation tab on or off, feed posting on or off, sub-tabs individually.
- A read-only **Email Log** lists every message the community has sent.
- The **spaces list shows each space's last activity** and can sort by it, so you can tell active spaces from quiet ones at a glance.
- **License** has its own entry in the WordPress admin menu.

## Fresh installs just work

A brand-new install is now genuinely ready out of the box: registration is live to match the default "Open" mode, new members are searchable immediately everywhere (directory, message recipients, site search), the onboarding wizard respects private spaces by sending a join request instead of granting instant membership, and demo data files every space under a category so the directory filters work from the first click.

## In BuddyNext Pro 1.0.4

Pro's release centers on membership:

- **A real billing area.** Members manage their plan from Settings > Membership: current plan, renewal date, payment history, and downloadable invoices.
- **Fair cancellation.** Cancelling keeps access until the period ends, then the member moves to a lapsed state - never an instant cut-off.
- **An editable pricing page.** The plan showcase is a normal WordPress page you restyle with the editor you already know.
- **Entitlements that explain themselves.** Every plan option says what it grants, right under its toggle.
- Plus a moderation quality pass: the bulk queue shows who posted what instead of raw IDs, and bulk member actions accept usernames or emails.

See the [Pro membership guide](../pro/01-membership-plans.md) for the full picture.

## Under the hood

For developers: add-on plugins can now register their own community hubs through the new `HubRegistry` (pages, rewrite rules, templates in one registration) and their own template directories via the `buddynext_template_locations` filter. The bundled Action Scheduler moved to 4.0.0, and every member-delete path funnels through one canonical purge event - including the WordPress GDPR eraser. Both plugins now require PHP 8.1.

The full changelog for both plugins is on each GitHub release: [BuddyNext 1.0.4](https://github.com/buddynext/buddynext/releases/tag/v1.0.4) and [BuddyNext Pro 1.0.4](https://github.com/buddynext/buddynext-pro/releases/tag/v1.0.4).

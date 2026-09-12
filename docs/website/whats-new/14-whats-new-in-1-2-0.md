# What's New in 1.2.0

This is an audit-driven release. We went back through everything the last few versions introduced and, alongside a broad sweep of mobile and privacy fixes, added real owner control: members-only posts, a say in which other plugins load on your community, and a clear choice about what happens to your data if you ever uninstall.

> **Note:** BuddyNext free and BuddyNext Pro are released together. If you run both, update them at the same time so they stay in step.

## Members-only posts

A member can now address a post to logged-in members only. The post stays publicly listed, but a logged-out visitor sees a short teaser and a prompt to join instead of the whole thing, so a community can share work openly with its members without putting everything in front of the open internet.

- The **Members only** audience appears in the composer for site admins and space managers, alongside Public, Followers, Connections and Only me.
- You set how much of a members-only post a visitor sees before the join prompt, with the members-only teaser control (25 percent by default).
- It is an audience level, not plan-gating: it separates members from the public. Restricting a post to a paid plan is still the Pro Content Protection feature.

## You decide which plugins load, and what happens on uninstall

Two settings hand the site owner control they did not have before.

- **Plugin isolation** lets you choose which other plugins load on BuddyNext's own community pages, so a heavy or conflicting plugin does not have to run where it is not needed. It stays **off by default** - every plugin keeps running until you opt in - and it will never remove a plugin that a plugin you kept depends on.
- **An uninstall data policy** lets you decide whether your community data is kept or removed if BuddyNext is ever uninstalled. Data is **kept by default**, so an accidental deactivate-and-delete is recoverable, and financial records are always retained.

## A warmer welcome for visitors

Logged-out visitors now get a clear path in rather than a series of dead ends.

- Join prompts appear on the landing and discovery pages and on an embedded space feed, and you can turn the guest call-to-action off if you would rather not show it.
- A visitor's **View profile** link opens the profile directly instead of bouncing through the login page first.

## Stronger moderation tools

- A community moderator can be given **moderation authority across the whole site**, not only inside the spaces they moderate. Editing roles and capabilities stays with admins.
- The **moderation log** can be filtered, exported to CSV, and have its background jobs run on demand from the admin, and the admin hub now shows background-job and data-retention status.

## Built for bigger communities

Follower, following, connection and space-member lists now use cursor-based paging, so they stay fast on a large community instead of slowing down as the numbers grow. If you build against the REST API, note that these list routes moved from `page` to a `cursor` returned as `next_cursor` (see the developer guide).

## Sharper on mobile

- The composer footer is at most two rows on a phone with no stranded icon, and comment and reply actions collapse to a compact icon bar.
- The comment box shows the commenter's real avatar, top-aligned, and its text is the same size as the comments below it.
- A long member handle no longer forces the members directory to scroll sideways, and the space feed's Search button no longer stretches full-width.
- The activity hub is now labelled **Activity**, the alerts area is **Notifications**, and the notifications **People** filter is **Follows**.

## Analytics you can trust (Pro)

- The monthly-active figure compares the same point in each month instead of a full month against a partial one, and the analytics strip says how fresh its numbers are.
- Each tile names the window it covers, the overview CSV exports the whole dashboard, and membership analytics show a per-member view count with a write throttle so heavy traffic does not inflate the figures.

## Safer money and moderation (Pro)

- A Stripe refund or dispute now revokes the member's paid access, and refunds, chargebacks and cancellations are recorded as distinct events.
- The AI moderation sweep can be run on demand, records every automated action as the system, and **fails closed** when its provider is unavailable, so content is never silently passed through as approved.
- You can register your own drip auto-enrollment triggers through a filterable registry.

## For developers

- A combined free and Pro **OpenAPI specification** is published with namespaced paths.
- New filter seams let a companion hub plugin coexist with the Spaces UI and read cross-space members and activity.
- The social-graph and space-members list routes are keyset-paginated (`cursor` + `per_page`, returning `next_cursor`); see the REST reference in the developer guide.

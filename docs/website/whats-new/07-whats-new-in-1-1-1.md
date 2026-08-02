# What's New in 1.1.1

BuddyNext 1.1.1 is a consolidation release. The headline additions are spaces that can own a photo album, Sign in with Apple, and an Articles tab for members who write - but most of the work went into the surfaces an owner actually administers. Every admin listing now behaves the same way on a phone as it does on a desktop, several privacy gaps closed, and a long list of things that looked finished but were not now are.

> **Note:** BuddyNext free and BuddyNext Pro are released together. If you run both, update them at the same time so they stay in step.

## Spaces can own albums

A space could always collect the photos shared in it, but an album belonged to a member. That meant a group's photos from an event lived in somebody's personal gallery, and left with them.

Spaces now own albums properly. Turn on the Media tab for a space and it gains an **Albums** view alongside the flat media grid: create a named album, upload into it, reorder it, and remove items - all from the space itself. The audience is the space, not a per-album setting, so a private or secret space's albums are invisible to non-members and stay out of search. A space owner can keep album creation with the organisers if they want the gallery curated.

The personal album surface is unchanged. Both use the same gallery and the same controls on purpose.

See [Space Media and Albums](../spaces/10-space-media-and-albums.md).

## Sign in with Apple

Apple joins the social login options, alongside the existing providers. It comes with the native-app connect bridge, so a member who signs in on the phone app lands in the same account they use on the web, and a sign-in that gets parked mid-flow returns to where it started rather than dropping the member on the home page.

See [Social Login](../accounts-access/03-social-login.md).

## An Articles tab for members who write

If your site runs WB Member Blog, member profiles now carry an **Articles** tab listing what that member has published - title, date, excerpt and cover, each linking to the post.

Looking at your own tab, you also get **Write a new article**, **Manage articles**, and an Edit link per row, all pointing at the blogging dashboard. Your drafts and posts pending review appear there too, labelled, so an unfinished piece never reads as published; nobody else sees them.

All writing and editing stays in Member Blog. BuddyNext only shows the work and points you back to where it is done.

See [WB Member Blog](../integrations/09-member-blog.md).

## A 2FA setup code you can scan

Two-factor enrolment showed a 32-character setup key and expected members to type it into their authenticator. It now shows a QR code, with the key still available for anyone who needs to enter it by hand. The email fallback is easier to find, and forced enrolment no longer dead-ends in a redirect loop when a verification hold is already in force.

See [Two-Factor Authentication](../accounts-access/05-two-factor-authentication.md).

## Admin screens that work on a phone

This is the largest single body of work in the release, and it is all one problem: BuddyNext's admin listings were built for a desktop and degraded badly below it.

Every admin list now becomes a set of labelled cards on a narrow screen instead of a table with its columns cut off. Tables that opted into nothing lost their columns silently - they no longer do. The row actions menu opens where you clicked rather than off-screen. Bulk Moderation, Members, Spaces, Announcements, Email Templates, Webhooks, Member Labels, Subscriptions, Invoices and the analytics tables were each specifically broken at some width and are each fixed.

Alongside that, every admin and profile control is now named for assistive technology, and destructive bulk actions ask you to type a confirmation rather than accepting a single click.

## Privacy and security

Several gaps closed, all of the same shape - content that should have been scoped to an audience was reachable by someone outside it.

- Closing a space left its posts publicly searchable.
- Private and secret space content was fully searchable by anonymous visitors.
- Account holds did not reach a partner plugin's REST surface, so a held member could still act through an integration.
- Space media over REST now applies the same three gates as the web Media tab.
- Rate limiting was silently disabled on any site without Redis or Memcached, and the decision itself is now atomic rather than just the counter.

## Fixes worth calling out

- Blocking now applies to comments. A block hid top-level comments but left replies and pinned comments visible.
- Declining a connection request makes it stop; the cooldown now also covers declines made before the upgrade.
- A private profile no longer tells visitors the member has never posted.
- Media attached to a post shows up whatever the post's type. An announcement posted with a photo used to lose the photo.
- Unknown member URLs answer 404 instead of a blank 200, and unknown space slugs answer 404 instead of a 500.
- Signup no longer wipes the email field after a failed validation.
- Suspended members can reach the appeal page, and the reaction route is no longer a dead end.
- Failed video embeds show a designed fallback instead of an empty frame.
- The leaderboard ran 312 queries to draw 50 rows. It no longer does.

## For developers

- `POST /app/strings` serves the mobile app its translations from the catalogues already on the site, and `/app/config` now carries a locale block.
- `IntegrationActivity::refresh()` lets a bridge correct the snapshot on a card it already published, instead of a wrong payload being frozen onto every member's feed.
- A `resume` post type is now distinct from `job`.
- The REST catalogue in `docs/api/openapi.json` covers all 208 Free endpoints.

See the [Developer Guide](../developer-guide/) for the full reference.

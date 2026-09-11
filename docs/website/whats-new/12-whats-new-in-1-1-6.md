# What's New in 1.1.6

Documents come to Spaces and profiles, the media lightbox is rebuilt, and pinning, announcements, notifications and privacy all get sharper. On the Pro side a plan can now bill monthly or annually, members can change their own plan, and memberships can be sold through WooCommerce and Paid Memberships Pro.

> **Note:** BuddyNext free and BuddyNext Pro are released together. If you run both, update them at the same time so they stay in step.

## Files come to Spaces and profiles

A community accumulates documents - a group's handbook, a member's portfolio, a shared template - and until now BuddyNext had nowhere to keep them.

- **Every space gets a Files tab**: its own document drive that members can browse, search, preview and download.
- **Every member gets a Files tab** on their profile, showing their own documents inside BuddyNext's own interface.
- **Documents open in a clean built-in reader.** A PDF renders as a readable single column instead of the browser's embedded viewer, and office and text files render inline.
- **Share a document** with specific members at a chosen permission, or with anyone through a link, from its page in the Files tab.
- **Attach a document to a post** from the composer - it shows as a document card in the feed.

On a site whose document storage is read-only, the attach control is simply hidden rather than failing when used.

## The media lightbox, rebuilt

Opening a photo now gives you a focused viewer with icon-only actions and an overflow menu, fullscreen, edit, and save-to-collection. A comment you make on a photo in the lightbox appears in the post's own comment thread, and the comment count stays accurate - the photo and the post are the same conversation, not two.

## Profile fields you arrange

Two changes hand the profile layout to you.

- **Choose which fields appear in the profile header, and in what order**, from the field editor.
- **Change a field's type in place** - for example a text location into an interactive map - without losing what members already entered.

## Pricing that fits how people pay (Pro)

Membership pricing was one plan, one interval. Now it flexes.

- **Monthly or annual on the same plan.** Members pick the cadence at checkout, see a savings badge on the annual option, and proration and renewal follow the interval they chose.
- **Members switch cadence themselves** between monthly and annual, no support ticket.
- **Upgrade and downgrade.** A member previews what a plan change is worth in days and applies it, without ever holding two plans or none for a moment.
- **A membership-pricing block** renders a full, option-driven pricing landing page.

## Sell memberships through the store you already run (Pro)

WooCommerce and Paid Memberships Pro can now grant BuddyNext plans through self-registering bridges. If you already take payment through one of those, a purchase there gives the member their community plan, and holders are reconciled when a product or level is remapped. A membership plan can also be linked to Learnomy courses and to a Space, so buying the plan opens the course and the group behind it.

## Spaces and members, polished

- **Per-space brand colour**, set by the space owner.
- **Filter the members directory by member type** with chips, matching how Spaces already works.
- **Announcements replace pinning inside a space.** Post pinning is now profile-only; to feature a post in a space, use an Announcement. Dismissing an announcement now sticks after a reload, announcements notify members in the background, and a whole batch of join requests can be decided in one action.
- **Notifications collapse repeats** of the same event and show an accurate count.
- **A gated space names the required plan** in its join-refusal message, so a member knows exactly what to buy.

## Privacy and security

- **Only-me media stays private.** A media item's privacy now follows its post, so "Only me" is never published publicly.
- **A private or secret space created through the API** is saved with that privacy instead of defaulting to public.
- **Inbound access-webhook calls must carry a replay-proof, timestamped signature** by default. A site still sending the older body-only signature can re-enable it while it migrates its callers.
- Closed a post-login open redirect and a route that ignored profile privacy, and the link-preview fetcher now re-checks its SSRF guard on every redirect hop.

## Other fixes

- Members can attach photos to a post again - the image picker no longer fails silently, and photo tiles reserve their space instead of flashing as blank cards.
- A document upload no longer announces itself as "shared a photo" in the feed.
- A members-only post in a space now shows on its hashtag page to members of that space, and a post set to "Connections" is visible to the author's connections on their profile.
- Reported posts stay out of the reporter's feed, and moderation-queue counts, suspensions, appeals and report labels are corrected.
- The admin Activity screen now browses, searches, filters, edits and deletes community activity with the standard paged control.
- An admin can mark a member's email as verified from the member editor, for when a verification email never arrives.
- A confirmation dialog opened from the media lightbox is clickable again.

## For developers

- New `[buddynext_search]` shortcode and a search-bar helper for themes.
- New filters `buddynext_profile_hero_fields` (which fields show in the profile header) and `buddynext_can_view_explore` (gate the Explore feed).
- A schema-authority gate means a table cannot be defined in two places.
- The end-to-end journey harness resolves the site and its test users from the local install instead of a hardcoded host.

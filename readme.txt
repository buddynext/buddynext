=== BuddyNext ===
Contributors: wbcomdesigns
Tags: community, social network, activity feed, groups, members
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The social layer for WordPress - activity feeds, spaces, member profiles, messaging, and moderation in one community platform.

== Description ==

BuddyNext turns WordPress into a modern social community. It gives members a real-time activity feed, profiles, spaces (groups), direct messaging, and the moderation tools a site owner needs to keep the community healthy - without bolting together a dozen plugins.

Everything is REST-first, so the same data powers the web experience and the native app.

= Community features =

* Activity feed with a rich post composer, reactions, comments, hashtags, polls, shares, and bookmarks.
* Spaces (groups) with membership, roles, and per-space content.
* Member profiles, a searchable member directory, and unified search across the community.
* Connections and follows between members.
* Direct messaging and media (powered by WPMediaVerse).
* Notifications and branded transactional email.
* Member onboarding, invites, and social login.
* Two-factor authentication (in-house TOTP, optional and opt-in).
* Reactive moderation: members post freely; reports go to a review queue for action.

= Built to extend =

BuddyNext is the free community OS. Three core integrations ship as companion plugins - WPMediaVerse (media and messaging), Jetonomy (discussions), and WB Gamification (badges, points, leaderboards) - and the application layer (memberships, monetization, AI, and business integrations) lives in BuddyNext Pro.

== Installation ==

1. Upload the `buddynext` folder to `/wp-content/plugins/`, or install the plugin zip through Plugins > Add New.
2. Activate BuddyNext through the Plugins screen.
3. Open the BuddyNext admin to run setup and create the community pages.

No Composer or build step is required - runtime dependencies are bundled.

== Frequently Asked Questions ==

= Does BuddyNext require BuddyPress? =

No. BuddyNext is a standalone community platform and does not depend on BuddyPress.

= Is there a Pro version? =

Yes. BuddyNext Pro adds the application layer - memberships and on-site checkout, advanced moderation, email automation, analytics, AI, and more. Free and Pro are released in lockstep.

= Does messaging require another plugin? =

Direct messaging and media are powered by the WPMediaVerse companion plugin. BuddyNext gates those surfaces until it is active.

== Changelog ==

= 1.0.3 - June 2026 =

Member media uploads and albums on the profile, plus large-community scale and stability fixes.

* New      - Members can upload photos and videos from their profile Media tab, choose who can see each upload, and the media appears in the activity feed right away.
* New      - Albums on the profile Media tab: create albums, add and remove media, set a cover, drag to reorder, rename, change privacy, and delete.
* Improve  - Photo and video previews now generate a fast downscaled thumbnail, so uploads on the Media tab, the feed composer, and direct messages feel instant.
* Improve  - Member-directory results refresh immediately after a block or unblock instead of serving a stale cached list.
* Fix      - The profile display-name field no longer reverts to the login name when you click away, so members can change their name.
* Fix      - A video without a poster image now shows a generated thumbnail instead of a black tile.
* Fix      - Editing or deleting a comment you do not own returns a clear permission message instead of a server error.
* Fix      - Ending or dismissing an announcement updates the home feed straight away.
* Fix      - Type-scoped search for members, spaces, and posts returns results whether the type is named in singular or plural form.
* Fix      - Appeal decisions and member warnings are now recorded correctly in the moderation audit log.
* Fix      - Hardened activity hooks so a third-party listener can no longer trigger a fatal error when a post is created.
* Compat   - Pairs with BuddyNext Pro 1.0.3. Install both updates together.

= 1.0.2 - June 2026 =

Theme-adaptive styling, an accordion admin nav, and a round of community fixes.

* New      - The admin left navigation collapses into an accordion that opens the active section and remembers your last open section.
* New      - Delete and Unsend actions in the direct-message menu.
* Improve  - BuddyNext adopts the host theme's colour scheme and font family (BuddyX, BuddyX Pro, Reign), so community pages match your theme; header icons follow the theme's header menu colour.
* Improve  - Sign-up Terms and Privacy links are admin-configurable instead of guessed from slugs.
* Improve  - Notification rows polished with a system-icon avatar and a clean fallback for unknown types.
* Fix      - Hashtag search returns results again, hashtags are indexed for every post type, and hashtag voting registers correctly.
* Fix      - The favourite toggle in the media lightbox now responds.
* Fix      - Removed the blank gap below the footer on BuddyX and BuddyX Pro auth pages, and fixed the header chrome layout inside the BuddyX header.
* Fix      - Host-theme button fill no longer leaks into message action buttons.
* Fix      - A deleted user's profile values and bookmarks are now purged.
* Dev      - Composer is no longer required at runtime (hand-written autoloader, vendor is dev-only).
* Compat   - Aligned with the WordPress 6.9+ and 7.0 Abilities API (ability category plus execute and permission callbacks).
* Compat   - Pairs with BuddyNext Pro 1.0.2. Install both updates together.

= 1.0.1 - June 2026 =

* New      - Media shared from the WPMediaVerse upload surface now appears in the community activity feed. Images post inline; audio and video link to the media page. A deferred, attached-to-post guard prevents duplicating media that was posted through the BuddyNext composer.
* New      - Link posts to supported providers (YouTube, Vimeo, and other oEmbed sources) now render an embedded player instead of a plain link card.
* Improve  - Link previews use the provider's real oEmbed title instead of a placeholder such as "- YouTube".
* Fix      - Resharing a photo or a video/link post now previews the original's image or thumbnail instead of rendering an empty quote.
* Fix      - The companion installer retries a transient store timeout once, so onboarding no longer fails to install a companion when the store is briefly slow to respond.
* Dev      - The admin hub name and logo resolve through the buddynext_brand_name and buddynext_brand_logo_url filters, and a shared logo-upload helper backs both Appearance and Pro white-label.
* Compat   - Pairs with BuddyNext Pro 1.0.1. Install both together.

= 1.0.0 - June 2026 =

The first stable release of BuddyNext - the community operating system for WordPress. A complete social layer in one plugin.

* New      - Activity feed with posts, polls, reactions, threaded comments, bookmarks, hashtags, and site-wide announcements.
* New      - Spaces: public, private, and hidden communities with membership, roles, and per-space feeds.
* New      - Member profiles with customizable field groups, a social graph (follows and connections), and a member directory.
* New      - Direct messaging with media, a conversation info panel with shared media and safety actions, and a full-bleed media lightbox.
* New      - Moderation suite: reporting, a review queue, strikes, suspensions, and appeals backed by an immutable audit log.
* New      - Onboarding wizard, gamification achievements, notifications, and a Progressive Web App with branded install icons.
* New      - Full translation readiness: every template, admin label, and JavaScript module is internationalized with a complete buddynext.pot.
* Compat   - Pairs with BuddyNext Pro 1.0.0. Direct messaging and media are powered by the WPMediaVerse companion plugin.

= 0.6.0-beta1 =

QA hardening across moderation, email, and navigation, membership enforcement seams for BuddyNext Pro, and a front-end plugin-isolation fix. Pairs with BuddyNext Pro 0.6.0-beta1.

* New      - Membership enforcement seams (entitlement gates) that BuddyNext Pro plans hook into.
* New      - Custom navigation tabs can now be deleted.
* Improve  - Moderation: full audit trail; the admin queue now surfaces action failures instead of false-success notices.
* Improve  - Email: every seeded template is shown in the editor; Preview Text is applied as the inbox preheader; sends are logged.
* Improve  - Navigation: Messages and integration-bridge options are gated on their required plugin being active.
* Improve  - Appearance logo and default theme reflect on the front end; pin/unpin updates the feed without a reload.
* Improve  - Settings: hide Connect for unconfigured social providers; gate Direct Messaging on WPMediaVerse.
* Fix      - Duplicate transactional emails on moderation actions (2-3 copies) reduced to one.
* Fix      - Social-login verified email is now recognized (meta key mismatch).
* Fix      - Isolation mu-plugin no longer strips BuddyNext Pro on front-end routes, and matches route segments exactly so pages like /membership/ are not mis-isolated.
* Fix      - Allow assigning an existing page whose slug matches a hub.
* Security - Masked secret input fields for admin credentials.
* Compat   - Pairs with BuddyNext Pro 0.6.0-beta1. Install both together.

=== BuddyNext ===
Contributors: wbcomdesigns
Tags: community, social network, activity feed, groups, members
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The social layer for WordPress — activity feeds, spaces, member profiles, messaging, and moderation in one community platform.

== Description ==

BuddyNext turns WordPress into a modern social community. It gives members a real-time activity feed, profiles, spaces (groups), direct messaging, and the moderation tools a site owner needs to keep the community healthy — without bolting together a dozen plugins.

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

BuddyNext is the free community OS. Three core integrations ship as companion plugins — WPMediaVerse (media and messaging), Jetonomy (discussions), and WB Gamification (badges, points, leaderboards) — and the application layer (memberships, monetization, AI, and business integrations) lives in BuddyNext Pro.

== Installation ==

1. Upload the `buddynext` folder to `/wp-content/plugins/`, or install the plugin zip through Plugins > Add New.
2. Activate BuddyNext through the Plugins screen.
3. Open the BuddyNext admin to run setup and create the community pages.

No Composer or build step is required — runtime dependencies are bundled.

== Frequently Asked Questions ==

= Does BuddyNext require BuddyPress? =

No. BuddyNext is a standalone community platform and does not depend on BuddyPress.

= Is there a Pro version? =

Yes. BuddyNext Pro adds the application layer — memberships and on-site checkout, advanced moderation, email automation, analytics, AI, and more. Free and Pro are released in lockstep.

= Does messaging require another plugin? =

Direct messaging and media are powered by the WPMediaVerse companion plugin. BuddyNext gates those surfaces until it is active.

== Changelog ==

= 1.0.0 - June 2026 =

First stable release of BuddyNext 1.0 - the community operating system for WordPress. See CHANGELOG.md in the repository for the full per-release history.

* New      - Full translation readiness: every front-end template, admin label, and JavaScript module is internationalized and shipped with a complete buddynext.pot.
* New      - Direct-message conversation info panel with shared media and safety actions; DM media opens in a full-bleed lightbox.
* New      - Progressive Web App ships branded BuddyNext icons (192/512, maskable) for install-to-home-screen.
* Improve  - Demo seeder indexes hashtags synchronously and seeds polls, bookmarks, and DM threads so every surface has content immediately.
* Improve  - Achievements profile tab: per-standing stat icons and equal-height badge medallions.
* Fix      - Profile and space feeds now honor viewer block/mute, closing a privacy leak.
* Fix      - Hashtag pages no longer report "does not exist yet" for a freshly seeded tag.
* Fix      - Profile headline saves instead of reverting, and member cards no longer duplicate the headline as the bio.
* Fix      - Admin handlers no longer report success on a failed save or upload.
* Security - Hardened all Plugin Check findings; zero security errors in first-party code.
* Compat   - Pairs with BuddyNext Pro 1.0.0. Install both together.

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

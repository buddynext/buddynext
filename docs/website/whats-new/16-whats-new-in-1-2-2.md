# What's New in 1.2.2

This release moves moderation into Community Admin and each space's own Moderation tab, gives Files folders and a Trash, and makes gamification and messaging follow their partner plugins more closely. It also adds a page cache guide and a broad accessibility and reliability sweep.

> **Note:** BuddyNext free and BuddyNext Pro are released together. If you run both, update them at the same time so they stay in step.

## Moderation lives in Community Admin and each space

- The standalone `/moderation/` page is retired. **Community Admin** is the front-end moderation home, and each space keeps its own **Moderation** tab for that space's reports.
- Community Admin pages through every open report, filters by type and sorts by most reported, so moderators without wp-admin access can work the whole queue. Report rows show the first line of what was reported, and the **More** menu adds View reported item, Warn author and Reverse last strike.
- A space's Moderation tab keeps Dismiss and Remove on each report and moves the rest into a More menu, with a working View reported item link. Reports on a space's posts and comments, including comment reports and reports filed from the app, always reach that space's queue.
- Open-report counts drop as you act, Warn and Remove from space only show for members they can act on, and profile reports offer member actions. On phones the report rows stack and the More menu is no longer cut off.
- The content-warning control shows a post's current warning and offers **Clear warning**, in Community Admin and in a space's Moderation tab.

See [Moderation Queue](../moderation/02-moderation-queue.md), [Space Roles and Moderators](../spaces/05-roles-and-moderators.md) and [Content Warnings](../moderation/08-content-warnings.md).

## Files: folders and Trash

- Space owners and moderators can create, rename and trash folders in a space's **Files** tab, and restore them from a new **Trash** view.
- The Trash shows who trashed each folder, when, how many items it holds and when it will be removed, with a **Delete now** action for people who can manage it.
- Folder controls follow WPMediaVerse's own rules: space owners and moderators manage every folder, and a member can rename or trash the folders they created.
- Smaller fixes: Link a file accepts the address of a BuddyNext Files page, files linked into a space open from its Files tab, and the Link a file panel closes on Escape or a click outside it.

See [Space Media and Albums](../spaces/10-space-media-and-albums.md).

## Revoke a space invite link

Space owners and moderators can now turn a space's invite link off without issuing a new one. Opening a space through a revoked, expired or used-up link now says the link is no longer valid, instead of showing a join button with no explanation.

See [Invite people with a link](../spaces/11-invite-with-a-link.md).

## Gamification and Kudos follow WB Gamification

- The Kudos tab follows WB Gamification 1.6.5: after you give kudos it shows "You gave X kudos" instead of the form, and the form is not offered when you have hit the daily ceiling. Switching Kudos off in WB Gamification removes the profile Kudos tab.
- Gamification screens use your site's own name for points, the Achievements rank reads **All-time rank**, and the leaderboard no longer shows a next-milestone widget. Earning categories on the Points tab use WB Gamification's own labels.
- A member's profile privacy now also hides their points, badges, rank and kudos from people who cannot see their profile, including on the leaderboard.
- Profile Achievements list badges in ladder order, the badge count is no longer capped at 24, and the level-up notification names the level you reached.
- Uploading a photo in WPMediaVerse earns its upload points once; the feed post it creates no longer pays again.
- Kudos, ranks and shared badges need WB Gamification 1.6.5 or later. BuddyNext also runs safely next to older WB Gamification and WPMediaVerse versions: a feature that needs a newer partner stays hidden instead of causing an error.

See [Kudos](../engagement/05-kudos.md), [Gamification](../engagement/01-gamification.md) and [Leaderboard](../engagement/02-leaderboard.md).

## Messages on or off in WPMediaVerse

- Messages are switched on or off in one place, **WPMediaVerse > Settings > Social > Messages**. **Platform > Features** shows whether they are on and links there.
- With Messages off, the member directory, space member lists, the notifications Messages filter and the Add to Menu box no longer offer a Message link, and message notifications are left out of the bell until Messages are on again.
- The Messages page tells an administrator that Messages are switched off in WPMediaVerse (with a link), and tells members plainly that Messages are turned off.
- The Message button on a space's member list opens a conversation with that member instead of the inbox.
- A message whose photo was deleted shows "This attachment is no longer available.", and a message shows the same time before and after a reload, in the site's timezone and time format.

See [Direct Messaging](../messaging-notifications/01-direct-messaging.md) and [WPMediaVerse](../integrations/02-wpmediaverse.md).

## Page cache and optimisation guide

A new guide explains what BuddyNext handles automatically, what was tested, and what to do after updates.

- Every community page a member is logged in on is now marked uncacheable, so a page cache that caches logged-in visitors no longer sends onboarding back to step 1 after Continue. It was tested with WP Super Cache, W3 Total Cache, WP Rocket and LiteSpeed Cache; guest views of public pages stay cached.
- With WP Rocket, guests now get cached and optimised profiles, posts and other community sub-pages.

See [Page Cache and Optimisation Plugins](../getting-started/09a-page-cache-and-optimisation.md).

## Notable fixes

- **Photos and feed posts.** A photo trashed in WPMediaVerse takes its feed post with it, restoring the photo brings the same post back, and deleting it permanently removes the post. See [WPMediaVerse](../integrations/02-wpmediaverse.md).
- **Explore.** Document posts stay off the public Explore page; they still show in their space's feed and Files tab and on the author's profile. See [Explore](../community/13-explore.md).
- **Timestamps.** On a host whose database clock is not set to UTC, new and edited posts, comments, follows, strikes and other records now store the correct time, so relative times, trending, digests and streaks line up.
- **For You.** Followers-only and connections-only posts from joined spaces no longer show to members outside that audience.
- **Archived spaces.** An archived space shows an Archived badge, offers no Invite, Join or Add sub-space, and refuses invitations and new sub-spaces. See [Creating a Space](../spaces/02-creating-a-space.md).
- **Front page.** When a community page is the site's front page, its deeper pages (Leaderboard, Explore, member profiles) open normally with their own title, and the home tab's canonical and og:url point at the site root.
- **Accessibility.** One Escape closes one layer of a dialog, Tab never leaves an open dialog, delete and revoke confirmations focus Cancel, and Search closes from anywhere in the overlay and returns you to where you were.
- **Restore defaults and Appearance.** Restore defaults previews each setting in the settings screen's own words, Roles & Capabilities uses the same confirmation as every other tab, and accent-coloured buttons pick black or white text by contrast. See [Admin Pages and Settings](../developer-guide/40-admin-pages-and-settings.md).
- **Upload limit.** The album picker, feed composer and Media tab use the site's real upload limit from WPMediaVerse and name it in the error.
- **Smaller fixes.** Confirmation messages now show after a page refresh, a space with an invalid field value saved before 1.2.1 no longer breaks its About tab, and a full search reindex includes spaces' searchable custom fields.

## For developers

- `IntegrationActivity::as_mirror()` wraps mirrored follows, lightbox comments and forum replies, so reward listeners can skip them with `IntegrationActivity::is_mirror()` and never pay one action twice.
- New `buddynext_render_drive_files()` shows a space's or member's Files UI on any page.
- New filters: `buddynext_search_space_object_type`, `buddynext_invite_link_dead_notice`, `buddynext_document_card_url` and `buddynext_discussion_card_url`.
- The `buddynext_mod_queue_columns` filter is removed with the retired page. `buddynext_mod_queue_row_actions` and `buddynext_moderation_queue_before` now fire in Community Admin.
- The REST API reference documents the options on a poll post in the single post, Explore and hashtag feed responses.

See [Integration Bridges](../developer-guide/44-integration-bridges.md), [Space Hooks](../developer-guide/29-hooks-spaces.md), [Search, Hashtags, Sidebar and Admin Hooks](../developer-guide/32-hooks-search-hashtags-sidebar-admin.md) and [Moderation, Auth and Trust Hooks](../developer-guide/31-hooks-moderation-auth-trust.md). The poll options in the REST reference have no matching page to link here.

## BuddyNext Pro 1.2.2

Pro 1.2.2 is the lockstep release. Install it with BuddyNext 1.2.2.

- On a host whose database clock is not set to UTC, subscriptions, coupons, broadcasts, drip sequences, moderation rules and gateway price links now store the correct time.
- The hourly post-limit moderation rule honours the post rate-limit exemption, so importing a community's recent posts is not cut short. See [Auto-Moderation Rules](../pro/14-auto-moderation.md).
- After an update, broadcasts and drip sequences that were in progress carry on. See [Broadcast Email](../pro/12-broadcast-email.md) and [Drip Sequences](../pro/13-drip-sequences.md).

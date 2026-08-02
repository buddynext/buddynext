=== BuddyNext ===
Contributors: wbcomdesigns
Tags: community, social network, activity feed, groups, members
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.1.1
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

= 1.1.1 - August 2026 =

Spaces can own photo albums, Sign in with Apple, an Articles tab for members who write, and integration cards that finally say what they are. Plus a large pass over the admin screens on phones, several privacy gaps closed, and a long list of things that looked finished but were not.

* New      - Spaces can own albums. Turn on a space's Media tab and it gains an Albums view: create a named album, upload into it, reorder it, remove items. The audience is the space, so a private or secret space's albums stay invisible to non-members and out of search.
* New      - Sign in with Apple, alongside the existing social providers, with the native-app connect bridge so a member signing in on the phone lands in the same account they use on the web.
* New      - An Articles tab on member profiles listing what that member has published, when WB Member Blog is active. Viewing your own tab also offers Write a new article, Manage articles, and an Edit link per row; your drafts and pending posts appear there, labelled, and nobody else sees them.
* New      - Two-factor enrolment shows a QR code to scan instead of only a 32-character key. The key is still shown for anyone entering it by hand.
* New      - The members directory can sort by Last Active.
* New      - Hashtag counts, and profile-field search across the directory and site-wide search.
* New      - WB Member Blog joins the companion catalogue, so it installs from inside BuddyNext like every other integration.
* Improve  - Job, listing and course cards in the feed now carry a short description and cover art when the source has one. A card with no image renders the compact text shape it always did, with no empty box reserved.
* Improve  - Integration cards say what happened again - "posted a new job", "completed a course". The verb was always passed and always dropped, which is why finishing a course produced two identical-looking cards instead of a completion and a certificate.
* Improve  - Integration cards use the same box as a reshared post rather than a bespoke one with a coloured edge; embedded content now looks like embedded content wherever it comes from.
* Improve  - Every admin listing becomes labelled cards on a narrow screen instead of a table with its columns cut off. Members, Spaces, Bulk Moderation, Announcements, Email Templates, Webhooks, Member Labels, Subscriptions, Invoices and the analytics tables were each broken at some width and are each fixed.
* Improve  - Every admin and profile control is named for assistive technology, and destructive bulk actions ask you to type a confirmation rather than accepting one click.
* Improve  - Moderation queues show when an offender is already suspended, and no longer invent report rows in an empty queue.
* Improve  - Failed video embeds show a designed fallback instead of an empty frame.
* Fix      - Media attached to a post now shows whatever the post's type. An announcement posted with a photo used to lose the photo.
* Fix      - An event card published straight from the create hook had no date on it. The card now resolves the event's real date, and existing cards repair themselves.
* Fix      - Choosing several Interests saved only the first, and saving again wiped the rest. The data was always correct; the edit form was prefilled with one value and faithfully saved what it had been shown.
* Fix      - The row actions menu in admin lists opened off-screen, which read as an unresponsive button.
* Fix      - Blocking now applies to comments. A block hid top-level comments but left replies and pinned comments visible.
* Fix      - Declining a connection request makes it stop, and the cooldown now covers declines made before this upgrade.
* Fix      - A private profile no longer tells visitors the member has never posted.
* Fix      - Forced two-factor enrolment dead-ended in a redirect loop when a verification hold was already in force.
* Fix      - Signup no longer wipes the email field after a failed validation.
* Fix      - Suspended members can reach the appeal page, and the reaction route is no longer a dead end.
* Fix      - Unknown member URLs answer 404 instead of a blank 200, and unknown space slugs answer 404 instead of 500.
* Fix      - The Notifications tab of Settings was narrower than the other three, so the page shifted as you moved along the tab strip.
* Fix      - An event with no cover image showed a blank block where the artwork belongs.
* Fix      - The account menu could not be closed by clicking the caret again.
* Fix      - Space owners can no longer strand their own space by changing their role; moderators manage a space, owners alone delete it or transfer it.
* Security - Closing a space left its posts publicly searchable.
* Security - Private and secret space content was fully searchable by anonymous visitors.
* Security - Account holds did not reach a partner plugin's REST surface, so a held member could still act through an integration.
* Security - Rate limiting was silently disabled on any site without Redis or Memcached, and the limit decision is now atomic rather than only the counter.
* Security - Space media over REST now applies the same three gates as the web Media tab.
* Perf     - The leaderboard ran 312 queries to draw 50 rows.
* Dev      - POST /app/strings serves the mobile app its translations from the catalogues already on the site, and /app/config carries a locale block.
* Dev      - IntegrationActivity::refresh() lets a bridge correct the snapshot on a card it already published, instead of a wrong payload being frozen onto every member's feed.
* Dev      - A resume post type, distinct from job.
* Dev      - The generated REST catalogue in docs/api/openapi.json covers all 208 free endpoints.

= 1.1.0 - July 2026 =

Continuous scrolling in the activity feed, a wider mobile layout on every screen, offline support, live message reactions, and a round of feed, composer, notification, profile and signup fixes.

* New      - The sign-up form subtitle is editable under Members > Registration & Login, so a paid-only community is not promised "Free forever".
* New      - A link preview can be removed while editing a post.
* New      - A Member type profile field can be shown on signup and profiles; the member's choice assigns their member type and is set once.
* New      - Losing your connection now shows a branded offline page with a retry, in your community's colours, instead of the browser's error screen.
* New      - The app config endpoint reports each installed integration with its on/off state and version, so a mobile client can hide a module the site does not run.
* Improve  - The onboarding stepper shows numbered dots with the current step labelled, on every screen size, so six steps never clip or scroll.
* Improve  - Messages runs edge-to-edge on phones, and the message field takes the full width with emoji, attachment and send on the line below.
* Improve  - Members with an imported profile address that breaks mentions can now be repaired with one click from BuddyNext > Members, not only from the command line.
* Improve  - The app now works offline as an app should: BuddyNext's styling is stored on the device, so an offline page looks like your community rather than an unstyled list of links.
* Improve  - Stylesheets, scripts and images are cached as they are used, capped so they cannot fill a member's device, and cleared automatically when the plugin updates.
* Improve  - The feed loads the next posts as you scroll, on the main feed, Explore and Bookmarks alike, without a page reload and without losing your place.
* Improve  - Community screens use the full width of a phone. Cards were running at about two thirds of the screen because three separate layers of spacing stacked up, and the width is now the same whichever theme is active.
* Improve  - The save bar on Edit profile and Notification preferences spans the width of a phone screen and shows its status there, so saving is confirmed instead of silent.
* Improve  - Announcement and pinned posts are marked by their label rather than a coloured card edge.
* Fix      - Everything past the first screen of the feed was inert: React, Comment, Share and Save did nothing on any post loaded after the first page.
* Fix      - Your own post now sits at the top of your feed for a few minutes after you publish it, instead of below every post from the people you follow.
* Fix      - Notification preferences could not be saved on any screen size. The Save bar was rendered at the far end of the page rather than pinned, and on phones the email frequency options were clipped by the card so "Off" was cut in half.
* Fix      - The count beside the Notifications heading went blank as the page finished loading, and dismissing an unread notification left the Unread tab still counting it.
* Fix      - Tapping Unread or Requests in Messages on a phone opened a conversation instead of showing the filtered list.
* Fix      - The announcement expiry and schedule fields in the post composer were pushed off the row on phones, so neither the date nor its clear button could be reached. Both now also name the site timezone they are read in.
* Fix      - Toasts, cookie notices and save bars could sit underneath the mobile navigation bar; every bottom-pinned surface now clears it from a single measurement.
* Fix      - The character counter in the composer kept the previous post's count after publishing.
* Fix      - The feed tab strip gave no hint that more tabs exist beyond the screen edge, a desktop-only instruction showed on phones, and a placeholder was clipped.
* Fix      - Onboarding kept the previous step's scroll position on Continue, so on phones the next step opened on blank space.
* Fix      - The settings tab strip gave no hint that more tabs exist beyond the screen edge; it now shows the same scroll chevrons as the main navigation on every browser.
* Fix      - A reaction on a message now appears for the other person live, instead of only after they reload the conversation.
* Fix      - Short pages like Notifications showed a large blank gap between the content and the footer on phones, and short lists now end with a clear end-of-list marker.
* Fix      - The onboarding steps were clipped at the right edge on phones; every input and step indicator now fits, and the step icon and heading share one line.
* Fix      - The mobile conversation list filled only part of the screen, which hid the All, Unread and Requests tabs and stopped the list scrolling.
* Fix      - Mobile navigation icons rendered far smaller than the centre button.
* Fix      - The mobile navigation bar covered the theme footer and copyright line.
* Fix      - Signing up behind a proxy that rewrote response headers reported a failure even though the account was created.
* Fix      - Activating the plugin could save the site's URL rules without BuddyNext's own, leaving community pages on 404 until permalinks were re-saved.
* Fix      - The signup form dropped the typed display name and used the email prefix instead.
* Fix      - Editing a choice profile field could replace its options with the word "Array" and discard its advanced settings.
* Fix      - The profile field editor hid the options and date settings when editing a Pro field type.
* Fix      - Delete account showed an error and never deleted the account.
* Fix      - Line breaks in a bio were lost, so a multi-line bio ran together on the profile.
* Fix      - The poll form kept its options and end date after posting, so the next post inherited them.
* Fix      - A space icon was clipped by the cover image on the settings page and showed square corners on the space page.
* Fix      - The profile photo edit button drifted away from the photo on phones and tablets, and the photo was too small for its own controls.
* Fix      - The profile header showed square bottom corners, and its tab strip drifted vertically on touch devices.
* Fix      - A Date profile field with a "Display as" option (Year only, Month and year, Age) showed a wrong full date on the profile instead of the chosen format.
* Fix      - Mentioning a member did not always notify them. The mention still linked to their profile, so nothing looked wrong, but no notification was sent when the member's username differed from their profile address - a space, a dot or an email in the username, or any member using a custom profile address.
* Fix      - Members whose profile address was imported with an email in it could not be mentioned, and did not appear in the mention suggestions. Run "wp buddynext handles check" to find them.
* Fix      - Registration warnings were hidden on BuddyNext's own Registration & Login screen - the screen you are on when configuring it, and the one BuddyNext's setup checklist sends you to. They now appear beside the setting they concern, and point to where the WordPress switch actually lives.
* Fix      - The admin member list and member edit screen showed each member's username where their public profile address belongs; the two differ when a username contains a space or a dot, and for members using a custom profile address.
* Security - Pages and API responses are never stored on the device by the offline cache, so nothing personal can be shown to whoever opens the browser next.
* Security - Marking the community private now also locks down the media/messaging REST API (WPMediaVerse), not only BuddyNext's own - closing a gap where media, tags and profiles stayed readable when logged out. Needs WPMediaVerse 2.2.0.
* Dev      - Addons can append onboarding wizard steps via the buddynext_onboarding_steps filter and render them with the buddynext_onboarding_render_extra_steps action.
* Dev      - Integrations can declare their plugin version when registering on the buddynext_integrations filter.
* Dev      - Content services accept a historical created_at from importers, so a migrated community keeps its original comment, space, connection, follow and reaction dates.
* Dev      - Added "wp buddynext handles check" and "wp buddynext handles repair" to report and normalise imported profile addresses. Repair reports only unless --yes is passed.
* Compat   - Ships in lockstep with BuddyNext Pro 1.1.0. Install and test both together.
* Compat   - The live-reaction fix needs WPMediaVerse 2.2.0, which adds the message-reaction updates to the messaging poll.

= 1.0.9 - July 2026 =

A redesigned admin with a Get Started home, sidebar widgets curated per surface, a profile About tab that renders any field, and the mobile app bootstrap endpoint.

* New      - A Get Started home greets you in wp-admin with a setup checklist, one-click demo data, theme suggestions, and quick links to every area.
* New      - The Features tab has a search box that filters the toggle list as you type.
* New      - The profile About tab renders any field group by its type, so custom groups and fields lay out cleanly without per-field code.
* New      - Every member upload now creates a feed post, so photos from the composer, the Media tab, or an album all appear on your feed.
* New      - GET /buddynext/v1/app/config gives the mobile app a single bootstrap handshake for site identity, feature flags, and the time contract.
* Improve  - Admin navigation reads clearer: Integrations is now Add-ons, Integration Display is now Integration Settings, and License and Add-ons have direct menu shortcuts.
* Improve  - Demo data can be loaded and removed right from the admin home, with the count of sample members, spaces, and posts shown.
* Improve  - Every admin list table shares one action-button style, one empty-state design, and an Actions column that stays visible when the table scrolls.
* Improve  - The members directory sorts by name and join date, admin tables show a consistent result count, and settings screens share one field-spacing rhythm and one bottom save bar.
* Improve  - Eventonomy joins the companion apps list, and a Recommended themes section presents BuddyX, BuddyX Pro, and Reign.
* Improve  - Sidebar widgets are organised per surface (feed, explore, members, spaces, profile, notifications, hashtags), each column curated for its page.
* Improve  - Upload progress spinners were added across every upload point: composer, DM attachment, avatar, cover, album picker, and onboarding.
* Improve  - Integration activity renders as a consistent, typed card in the feed for events, badges, and other connected apps.
* Improve  - Badge and level-up notifications from integrations are collected for display in one place and never send a duplicate email.
* Improve  - Member directories, the space roster, and the discovery asides are cached per viewer for large communities.
* Improve  - Profile fields render true to their type: paragraphs are multi-line, multiselect values are separate chips, and Work and Education show dates and admin-added sub-fields.
* Fix      - Members list action buttons are aligned, and the redundant Last Login column was removed in favour of Last Active.
* Fix      - Stray divider lines, duplicated section headings, and mismatched Edit and Delete buttons were cleaned up across admin screens.
* Fix      - Profile Replies and Likes tabs could show a private post's content to anyone.
* Fix      - A media item added to an album from the picker had no source post, so its single-media link bounced to the Media tab instead of the post.
* Fix      - A feed card was left behind after its source media or its event was deleted.
* Fix      - A profile field could not be created when its name matched an existing one, and renaming a field group from wp-admin did not stick.
* Fix      - GET /spaces/{id} now returns the viewer's membership block, and provisioning a space forum surfaces the Discussions tab.
* Fix      - The People to Follow widget overlapped names, and several wp-admin toolbars and row-action menus were misaligned.
* Dev      - App-facing REST timestamps carry a UTC ISO *_gmt sibling via one dispatch seam, filterable per namespace.
* Dev      - The members directory query is prepared in the model instead of the template.
* Compat   - Ships in lockstep with BuddyNext Pro 1.0.9. Install and test both together.

= 1.0.8 - July 2026 =

Abuse reporting for media, a manageable header menu, and a large pass over scale, moderation and privacy.

* New      - Report media and block its uploader directly from the media lightbox. Reports go to the media moderation queue.
* New      - Manage the header account dropdown from Settings - Navigation: hide, rename, reorder, and add your own links.
* New      - Reorder the mobile bottom navigation tabs by drag and drop. The centre Create button stays centred.
* New      - Reschedule a scheduled post. Editing it now lets you move its date instead of deleting and reposting.
* New      - wp buddynext qa-fixtures generates deterministic edge-case and scale data for testing.
* Improve  - Scheduling now reads and writes in the site's timezone (Settings - General), and the control names the zone.
* Improve  - "Require approval to join" is shown only on Open spaces, where it has an effect. Private and secret spaces explain their own join rules.
* Improve  - Search, directories, connections and the activity feed are indexed and paginated for large communities.
* Improve  - Outbound webhooks deliver one job per endpoint instead of one long blocking run.
* Improve  - Disabling an integration now removes its content from search instead of leaving it indexed.
* Improve  - Deleting a custom reaction no longer locks the reactions table.
* Fix      - A member at the hourly post limit could not edit any of their existing posts. Content rules still apply to edits.
* Fix      - Publish Now published at the time the post was composed rather than the moment you pressed it.
* Fix      - A published post could be pulled back out of the feed by rescheduling it.
* Fix      - The profile REST payload still returned a full date of birth when the field was set to show only an age or a year.
* Fix      - Nobody could register through the web signup form on a default install.
* Fix      - The Integrations tab reported "Changes saved successfully" when it had saved nothing.
* Fix      - Editing a profile field while its add-on was inactive destroyed the field's type.
* Fix      - The new-posts pill counted a member's entire backlog and printed an uncapped number.
* Fix      - The PWA service worker installed, reported active, and did nothing.
* Fix      - A member whose name contained non-ASCII characters could not be found in search.
* Fix      - An auto-suspend rule the owner had configured never fired.
* Fix      - Search reported a total it could not actually return.
* Security - The members directory no longer prints a member's WordPress username on the public "online now" list; it shows the public profile slug instead.
* Security - Deleting the free plugin destroyed the customer's invoices and other paid records.
* Security - An invitation is now bound to the address it was sent to and is no longer usable as a bearer token.
* Security - Hardened the outbound request guard that could abort an entire profile save.
* Dev      - The buddynext_safeguard_check filter now receives a $context argument ("create" or "edit") so create-time rules can skip edits.
* Dev      - The GDPR export is derived from the erasure registry, so a table that is erased is also exported.
* Compat   - Ships in lockstep with BuddyNext Pro 1.0.8. Install and test both together.

= 1.0.7 - July 2026 =

* New      - Private Community lockdown so the whole community can be members-only, with a buddynext_private_community_can_access filter to customise access.
* New      - "Get your community live" first-run setup checklist on the admin dashboard that auto-tracks your setup progress and dismisses when done.
* New      - Two-way discussion sync between the activity feed and Jetonomy forums, propagating edits and deletes both ways.
* New      - Unban control for members banned from a space, so an owner can reverse a ban from the space settings.
* New      - Unpin control on a space's pinned-posts strip.
* New      - Unread direct-message badge on the header Messages icon.
* New      - "Resend verification email" action in the composer when a member's email is not yet verified.
* Improve  - The add-field form now sets "Show on registration" directly and explains what each field type does.
* Improve  - The Skills profile group can now be removed, like the other starter groups.
* Improve  - The three gamification profile tabs are folded into a single Achievements tab.
* Improve  - Error toasts stay longer and are dismissible instead of auto-hiding early.
* Improve  - Space discussion buttons open the new-topic composer directly.
* Improve  - Members stay on the community front end after login and logout.
* Improve  - Dark mode now covers native form controls, skill chips, leaderboard badges, and the BuddyX and Reign mode toggle.
* Fix      - The delete-group confirmation in the profile-fields admin no longer overlaps the toolbar controls.
* Fix      - The profile "Edit avatar" and "Edit cover" links now open the matching picker.
* Fix      - Spaces filter pills no longer shrink or vibrate on hover, verified on BuddyX and Reign.
* Fix      - Reposting no longer fails silently and single-post comments toggle correctly.
* Fix      - Private images now display in the media lightbox for viewers allowed to see them.
* Fix      - The mobile bottom navigation no longer covers Save and Cancel on the Profile Edit and Settings screens.
* Fix      - Navigation and profile-tab labels now respect the active translation.
* Fix      - The post pin label no longer reads "Pin to profile" inside a space.
* Fix      - The gamification "on cooldown" points nag is suppressed; points are awarded silently.
* Fix      - Turning off the desktop sidebar rail no longer leaves an empty column on hub pages; content reflows to full width.
* Fix      - Member profiles with an empty sidebar no longer show a large blank gap on the right.
* Fix      - The Spaces directory count now matches the spaces shown instead of also counting hidden sub-spaces.
* Fix      - Profile action menus, the Share popover, and the Block and Report dialogs now close when you click outside them.
* Fix      - The "Remove Demo Data" button stays readable on hover instead of turning blank.
* Fix      - The media lightbox comment box now matches the BuddyNext style and centres its empty state instead of inheriting the theme's fonts and leaving a blank gap.
* Fix      - Linking an existing discussion in a space's settings now shows an error if the search request fails, instead of silently returning no results.
* Fix      - The License activation screen loads its styles and scripts correctly on live hosts with a symlinked or non-standard document root, instead of 404ing its assets.
* Security - Media lightbox actions are gated to logged-in viewers.
* Dev      - New buddynext_redirect_url filter for login and logout redirect control.
* Dev      - Notification and PageRouter audit fixes: removed dead code and corrected dependency wiring.
* Compat   - Aligned with BuddyNext Pro 1.0.7. Install both updates together.

= 1.0.5 - July 2026 =

Stability and integration hardening pass across auth, spaces, messaging, moderation, notifications, and search.

* New      - Media shared to the community links back to the activity it was posted in; a dedicated per-item media page is opt-in under Settings.
* New      - Direct messages gain per-conversation mute, load-older history, and in-thread search.
* New      - Scheduled posts get an hourly catch-up sweep so a missed cron never strands a post.
* New      - The member profile API exposes the Profile Strength checklist, and developers can reshape the checklist for custom profile schemas via a filter.
* Improve  - One navigation source powers both the web rail and the native app, so menus stay in sync.
* Improve  - Visiting Notifications clears the unread badge, and search adds a debounced typeahead with viewer-scoped visibility so members find only spaces they can see.
* Improve  - Onboarding can seed opt-in sample content from the setup wizard, and companion installs retry per row.
* Fix      - Authentication hardening: login rate-limiting, an email-verification gate before first sign-in, and an application-password token flow for the native app.
* Fix      - Spaces: a deleted space returns a proper 404 instead of a fatal, decline notifications send, and member avatars resolve in the API.
* Fix      - Followers and following lists respect connection visibility.
* Fix      - Auto-moderation matches on word boundaries (no false positives on substrings) and covers profiles and direct messages; bulk moderation runs asynchronously with progress.
* Fix      - Required profile fields are enforced on the full write path, and member-directory visibility honors the configured tier.
* Fix      - Search suggestions return grouped results and boost exact and prefix name matches.
* Fix      - Toggle switches keep a visible off-state track, the selected tab keeps its highlight, and modal close buttons keep their hover state under themed buttons.
* Fix      - Profile completion rewards fire only when the completion percentage actually changes, closing a refresh-to-farm-points loophole and a repeating cooldown notice.
* Fix      - Hashtag, mention, member, and space links resolve correctly on sites that renamed their community page slugs.
* Fix      - The profile completion reward milestone now follows the Profile Strength checklist members actually see, so finishing the visible tasks triggers it reliably.
* Fix      - Reshared posts cap their image and video preview height instead of filling the screen on wide layouts.
* Fix      - Email unsubscribe now shows a clear confirmation, digest unsubscribes actually stop digest emails, and digest emails carry working links instead of raw placeholders.
* Security - Registration and auth stay self-contained (in-house spam protection and optional TOTP two-factor); no third-party captcha is reintroduced.
* Dev      - Outbound webhooks cover membership events with a filterable catalogue, 30-day log retention, auto-disable on repeated failure, and PATCH updates.

= 1.0.4 - June 2026 =

* New      - Members can pick their interests from your space categories during onboarding and edit them any time on their profile, where each interest links to the matching spaces in the directory.
* New      - People and space suggestions are now personalized by the interests each member picks, so a brand-new member sees relevant members and spaces to follow and join from their very first session.
* New      - The For You feed ranks posts from spaces in a member's picked interests higher, and Explore suggests popular spaces from those interests instead of only the newest ones.
* New      - Developers can add their own per-space settings that appear on the space management screen, save automatically, and are available over the REST API - the same system the built-in space settings now use.
* New      - Profile sections can be limited to a member type, so each type gets its own profile fields - a restricted section appears only on profiles of members with that type and never on the signup form.
* New      - Every profile field can carry owner-written help text under its name and an example placeholder inside the input, shown on both the profile editor and the signup form.
* New      - A My Spaces view at /spaces/mine/ lists the spaces a member belongs to, with a friendly empty state.
* New      - Direct messages show a live typing indicator while the other person writes.
* New      - A read-only Email Log in the admin lists every message the community has sent, so owners can answer "did that email go out" without guesswork.
* New      - Connection request rows show the requester's headline and how many connections you share.
* New      - Space cards in the directory show how many sub-spaces a space has, counting only the ones the viewer is allowed to see.
* Improve  - Core profile fields (bio, headline, location) are now protected from accidental deletion, so search and member cards keep working no matter how the profile form is customized.
* Improve  - The Social Links, Work Experience, and Education profile sections can now be removed when they do not fit your community; profile pages simply hide a section that is gone.
* Improve  - Deleting a profile field or group that holds member data now shows exactly how many members are affected and asks you to type its name to confirm, and the cleanup runs in small background batches so large sites stay responsive.
* Improve  - Member lists inside a space and nested sub-spaces stay fast in very large communities.
* Improve  - Per-space settings no longer load on every page request, keeping large sites fast as the number of spaces grows.
* Fix      - Required profile fields are now enforced when saving: an empty value is rejected with a clear message next to the field instead of being silently accepted.
* Fix      - The setup wizard's profile sections now create the same real field types as a fresh install (URL inputs, date pickers, yes/no checkboxes) instead of plain text boxes with mismatched field names.
* Fix      - A photo added to the activity composer is shared only when you click Post; removing it or leaving the page no longer publishes it on its own.
* Fix      - Sharing a photo from the media viewer now opens the full Share menu with Repost and Copy link instead of silently copying the page link.
* Fix      - Deleting a space now keeps its sub-spaces by moving them to the top level, instead of leaving them stranded under a space that no longer exists.
* Fix      - The privacy choice on each Work Experience and Education entry is saved and shown correctly after you reload the profile editor.
* Fix      - The site-wide login redirect setting is now honored, so members land on the page you configured after signing in.
* Fix      - The after-login, after-logout, and after-onboarding redirect fields on Registration & Login now save, so the destinations you set are actually applied.
* Fix      - Signing in from a page a caching layer served stale no longer fails with "Cookie check failed" - the form retries with a freshly minted security token instead of re-sending the stale one baked into the cached page.
* Fix      - The email verification message is now actually sent when you require email verification; it was silently dropped, leaving new members waiting on the "check your inbox" screen forever.
* Fix      - The Welcome email now uses the template you edit in the admin; it previously sent built-in copy no matter what you wrote.
* Improve  - All email subject lines now follow one consistent style; your own customized subjects are left untouched.
* Fix      - The confirmation popup for removing media or deleting an album now explains what you are confirming; it previously opened with empty text.
* Fix      - Removed a repeated PHP notice about translations loading too early that filled the debug log on WordPress 6.7 and newer.
* Fix      - The community rail and mobile navigation toggles can now actually be turned off; unchecking them previously saved but silently reverted to on.
* Fix      - Changing a community page slug now takes effect immediately instead of returning "page not found" until permalinks were re-saved by hand.
* Fix      - Turning an integration menu off no longer silently disables its sub-tabs behind the scenes when you next save the screen.
* Fix      - Resetting an email template to default now restores the standard copy immediately; it previously left that email silently disabled until re-saved.
* Fix      - The media lightbox reaction bar now honors your enabled-reactions choice instead of always showing all six.
* Fix      - The email digest setting now reflects its real state; a fresh install previously displayed Disabled while digests were actually on.
* Fix      - The allowed email domains list is now enforced even when spam protection is switched off; it is an access policy, not a spam check.
* Fix      - The member data-export and account-deletion permissions can now actually be turned off on a fresh install.
* Fix      - New spaces now honor the default space type chosen in Spaces settings; the create dialog previously always preselected Open.
* Fix      - Deleting a member now also removes their pending email-verification tokens immediately.
* Fix      - Values saved in owner-created repeater profile groups now persist; they previously vanished after a success message.
* Fix      - Removed a PHP warning that fired on profiles whose fields hold multiple values, such as interests.
* Fix      - BuddyNext emails now always carry your configured sender identity, even when another plugin overrides the site-wide email sender.
* Improve  - The admin left navigation and stacked toggle rows now breathe with the same calm rhythm as the rest of the Wbcom admin family.
* Improve  - License moved to its own entry in the WordPress admin menu, so activating it no longer means hunting through tabs.
* Improve  - Every guest-facing login and register link routes through the community's branded auth pages instead of the bare WordPress form.
* Improve  - Members read as offline the moment they log out, and the feed's new-post check stops re-counting when nothing changed.
* Improve  - Choosing a member type at signup now shows each type's description, not just its name.
* Improve  - Integration Display is rebuilt as one card per integration with proper switches and descriptions instead of a plain checkbox list.
* New      - The admin spaces list shows each space's last activity and can sort by it, so you can tell active spaces from quiet ones at a glance.
* Fix      - Registration works out of the box: a fresh install now enables WordPress registration to match the plugin's default Open mode.
* Fix      - New members are searchable immediately in the members directory, messages, and site search, even on hosts where background jobs cannot run.
* Fix      - The onboarding wizard respects private spaces: joining one now sends a join request instead of granting instant membership, and the wizard only suggests open spaces.
* Fix      - Demo data files every space under a category so the directory's category filters work from the first click.
* Improve  - The admin works comfortably on iPad: the navigation keeps its sidebar layout instead of pushing content below the fold.
* Improve  - The Webhooks screen now uses the full panel width and lays event choices out as a comfortable grid.
* Improve  - Logo fields now use the WordPress media library with a preview and Remove button instead of a bare file input.
* Improve  - Settings cards fill the panel on every screen with inputs capped at a comfortable reading width, and the Custom CSS box gains syntax highlighting.
* Improve  - The Navigation screen leads with plain language; developer hook names and the capability field now live behind For developers and Advanced disclosures.
* Fix      - The Integrations screen no longer shows a Save button with nothing to save, drops a duplicate Connected label, and its description now matches the companion grid it shows.
* Fix      - Keyword rules set to Block now reject a new member's post instead of only holding it for review; a block always outranks a hold.
* Fix      - Posts using a banned hashtag are now rejected as the setting promises; previously the tag was silently dropped while the post published.
* Fix      - Blocked domains now also catch links pasted into the post text and subdomains of a blocked entry, not just the attached link field.
* Fix      - Moderation Log entries now record what was acted on and show correct times on hosts whose database timezone is not UTC.
* Fix      - Choosing a member type in the directory now filters the member list, and each type count matches the members shown when you open it.
* Fix      - A private space now shows a single join button instead of two.
* Fix      - The direct-message typing indicator clears as soon as you stop typing or send, instead of lingering.
* Fix      - The profile Media tab, albums, and other interactive profile sections load reliably, and album dialogs no longer flash open on page load.
* Fix      - The "loading more posts" indicator shows a clean loading bar instead of an empty bordered box.
* Fix      - Dragging a navigation tab by its handle in Settings > Navigation now reorders it; the drag was previously ignored, forcing you to edit each tab's position number by hand.
* Fix      - On your profile Activity tab, the post box now has a gap below it instead of sitting flush against the first post.
* Fix      - A secret space's name no longer appears in the browser tab title for members who cannot see the space.
* Fix      - Sharing works from the profile feed too; the share dialog previously opened only on the main feed.
* Dev      - Add-on plugins can register their own community hubs (pages, rules, templates) through the new HubRegistry, and their own template directories via buddynext_template_locations.
* Dev      - Bundled Action Scheduler updated to 4.0.0.
* Dev      - Every member-delete path funnels through one canonical purge event, including the WordPress GDPR eraser.
* Compat   - Requires PHP 8.1. Pairs with BuddyNext Pro 1.0.4 - install both updates together.

= 1.0.3 - June 2026 =

Member media uploads and albums on the profile, plus large-community scale and stability hardening.

* New      - Members can upload photos and videos from their profile Media tab, choose who can see each upload, and the media appears in the activity feed right away.
* New      - Albums on the profile Media tab: create albums, add and remove media, set a cover, drag to reorder, rename, change privacy, and delete.
* New      - Links you paste into a post or comment now turn into clickable links.
* New      - Object-cache health indicator on the Tools screen so owners can confirm a persistent cache is active.
* Improve  - Community pages, the home feed, search, widgets, and polls are cached and free of per-row queries, so they stay fast as membership grows.
* Improve  - Photo and video previews now generate a fast downscaled thumbnail, so uploads on the Media tab, the feed composer, and direct messages feel instant.
* Improve  - Online presence reads from an indexed table with object-cache throttling, so the online-members list stays accurate without loading the database.
* Improve  - Background jobs run through Action Scheduler with automatic retention pruning, keeping scheduled-task tables small.
* Improve  - Direct-message threads stop polling when the tab is hidden or closed, reducing battery and server load.
* Improve  - The following count is capped to keep the home feed fast for highly-followed accounts.
* Improve  - Member-directory results refresh immediately after a block or unblock instead of serving a stale cached list.
* Improve  - The mobile bottom navigation bar is taller with larger tap targets, and the center Create button opens the composer ready to type.
* Improve  - Admin settings fields, sidebar icons, and Explore result cards are visually consistent across every screen.
* Fix      - The profile display-name field no longer reverts to the login name when you click away, so members can change their name.
* Fix      - A video without a poster image now shows a generated thumbnail instead of a black tile.
* Fix      - Posting a poll without a question now shows a prompt to add one, instead of the Post button doing nothing.
* Fix      - The emoji button in the comment box now lines up with the send button.
* Fix      - The profile editor no longer warns about unsaved changes after you have already saved.
* Fix      - If the bundled licensing and update SDK is ever incomplete, the site stays up with a notice instead of a critical error.
* Fix      - Editing or deleting a comment you do not own returns a clear permission message instead of a server error.
* Fix      - Ending or dismissing an announcement updates the home feed straight away.
* Fix      - Type-scoped search for members, spaces, and posts returns results whether the type is named in singular or plural form.
* Fix      - Appeal decisions and member warnings are now recorded correctly in the moderation audit log.
* Fix      - The Online Members widget now lists members who are actually online.
* Fix      - Deleting a space clears its member and ban caches immediately.
* Fix      - Hardened activity hooks so a third-party listener can no longer trigger a fatal error when a post is created.
* Dev      - Removed legacy presence dual-writes, a dead database table, and unused cache methods; per-space settings and custom CSS no longer autoload.
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

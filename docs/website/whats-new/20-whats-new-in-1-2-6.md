# What's New in 1.2.6

This release lets you hand moderation work to community admins and moderators without making them WordPress administrators, shows integration activity on Explore as proper cards, and fixes mentions, polls, covers, linked forums and caching. BuddyNext Pro 1.2.6 makes plan limits clearer and fixes event card dates.

> **Note:** BuddyNext free and BuddyNext Pro are released together. Update both at the same time and test them together.

## New for site owners

- **Unlink a space's discussion.** A space owner can unlink the space's discussion under **Settings > Integrations**. The forum, its posts and every space member stay, and Discussion can be turned on again later. See [Space Forum](../spaces/09-space-forum.md).
- **Your own login page.** Developers can send every BuddyNext Log in link and guest gate to their own login page with the `buddynext_login_url` filter. See [Your own login page](../developer-guide/57-your-own-login-page.md).
- **Your Site Icon as the app icon.** The installable app uses your WordPress Site Icon when one is set, and BuddyNext's own icons otherwise. Change it under **Settings > General** in WordPress. See [Progressive web app](../engagement/04-pwa.md).

## Better for site owners

- **Community moderators.** Community moderators can remove any comment, bulk-moderate, review posts from suspended or shadow-banned members and work the report queue, all without being WordPress administrators.
- **Permissions you can hand out.** Open-ended suspensions, appeals, editing other members' posts and comments, site-wide announcements and role changes are permissions you can give to community admins or moderators under **Roles & Capabilities**. See [Roles and capabilities](../developer-guide/39-roles-and-capabilities.md).
- **Report alerts.** Community moderators and admins are told about new reports, and the notification opens the front-end moderation queue. Space owners and moderators see the new-report setting in their notification preferences.
- **Editing profiles.** Anyone you give "Edit anyone's profile" can also change a member's member type, and can open the profile editor from a page using the members shortcode.
- **Explore.** Each integration activity is its own card: events with their date, place and cover, badges with their artwork, and jobs, listings, courses and "open to work" with their details.
- **Integration versions.** The readme lists the minimum version of each integration plugin and what happens on an older one.
- **One timezone for every date.** Dates and times show in your site's timezone on member and admin screens, so a joined date, an expiry or a birthday reads the same everywhere.

## Fixes

- Finishing a word while @mention or #hashtag suggestions are still loading no longer opens a stale list or inserts "null".
- Logged-out visitors see the real poll percentages instead of 0%.
- An admin screen whose feature is switched off says so and links to Features.
- On sites using Redis or Memcached, comments, votes, reactions, edits and blog post cards update straight away.
- Explore space cards show the space cover, and Explore and the Spaces directory show the part of the photo the owner positioned on the space page.
- A shared badge shows its artwork and what it was awarded for, including badges shared before this update.
- Initials on the generated picture for members without a photo are readable on every colour.
- A private space's linked forum lets in members who joined the space before the forum was linked.
- Warnings and strikes sent from the app, an integration or the API need a reason.
- The Docs link on Get Started, Automation and Integration Settings opens the matching help page.
- On phones the Spaces directory shows featured spaces once.
- Changing your member type no longer warns about unsaved changes.
- With email verification on, a site administrator reads as verified in the app.
- Search no longer shows deleted members, and the update clears search entries for members, posts and spaces deleted before this version.
- Birthdays and other date-only profile fields no longer show a day early or late when the site timezone is not UTC.
- Profile Strength no longer counts a section as complete while a required field in it is empty.
- A space moderator can no longer ban or remove a member who outranks them, and moderators can no longer warn, strike, suspend or shadow-ban one. Those controls are hidden for that member.
- Notification types that only reach the site owner are no longer offered to members in notification settings or the app.
- After a space unlinks its discussion, banning or removing a member still removes their forum access, and turning Discussion back on offers the old forum.
- The Messages screen shows the standard empty state when you have no conversations.

## Security

- A post from a shadow-banned member, or a suspended member whose posts are hidden, no longer opens for other members by direct link or through the app.

## For developers

- **Filters.** New filters `buddynext_login_url`, `buddynext_integration_card_types` and `buddynext_integration_card_preview`, and the action `buddynext_space_discussion_unlinked`.
- **REST.** `DELETE /spaces/{id}/discussion` unlinks a space's discussion. The REST API reference is regenerated for 1.2.6.
- **Auth links.** The password reset email always opens the BuddyNext reset screen, and a login, signup or reset link filter that returns WordPress's own address no longer loops.
- **Tests.** The PHPUnit suite installs its tables before the first test, so it runs on a fresh test database.
- **Notification audience.** Integration notification types can declare `audience` as `owner` or `moderator`; see [Notifications and email hooks](../developer-guide/30-hooks-notifications-email.md).

## BuddyNext Pro 1.2.6

Clear plan limits for group messages and pinned posts, and every bridge tested against the latest partner releases.

- **Open to work.** "Open to work" cards show the member's headline and photo from their resume.
- **Plan limits.** A member whose plan caps group size is told the limit, and a plan saved before a limit had a ceiling reports the capped number over the API. Site administrators can always join a space whose access rule predates membership plans.
- **Events.** Event cards show the event's own date when the site's timezone differs, and cards posted before this update are corrected.
- **Bulk moderation.** Bulk moderation works on phones, and Bulk Warn and Bulk Suspend ask for a reason when it is empty.
- **Compatibility.** Tested with Eventonomy 1.9.0, WP Career Board 1.8.1, WB Listora 2.0.0 and Learnomy 2.1.3.
- **Dates.** Membership, invoice, receipt and billing dates show in the site timezone, matching the admin screens.
- **Trials.** A trial bought on a gateway BuddyNext does not bill, such as the Test gateway, no longer promises a cancel the member cannot make.

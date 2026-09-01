# What's New in 1.0.7

BuddyNext 1.0.7 is about control and polish: run the whole community behind a login when you want to, get a clear first-run checklist that walks you to launch, and keep discussions in sync between the activity feed and your forums. Around those headline features sits a broad quality pass across dark mode, the admin, and dozens of member-facing details. This page is a plain-language tour of what changed and why it matters.

> **Note:** BuddyNext free and BuddyNext Pro are released together. If you run both, update them at the same time so they stay in step.

## Run a private, members-only community

You can now lock the entire community behind a login. When Private Community is on, guests who are not signed in are sent to your login or landing page instead of the feed, members, and spaces, so the whole experience is members-only.

It is a single switch, and it respects your existing pages: the login, registration, and any pages you have marked as public stay reachable so people can still get in. Developers who need finer rules (for example, allow a specific role or a paid plan only) can decide access themselves with the `buddynext_private_community_can_access` filter.

## A first-run checklist that gets you live

New communities now open to a "Get your community live" checklist on the admin dashboard. It tracks the steps that matter for a real launch, ticks them off automatically as you complete them, and disappears once you are done, so a fresh install is never a blank screen with no next step.

## Discussions that stay in sync

If you connect BuddyNext to Jetonomy forums, discussions now sync both ways. Start a discussion from the activity feed and it appears in the forum; edit or delete it in either place and the change propagates to the other. There is no manual re-posting and no drift between the two surfaces.

Space discussion buttons also now open the new-topic composer directly, so starting a conversation is one click instead of two.

## Smaller features members and owners will notice

- **Unban from a space.** An owner can reverse a space ban from the space settings, so a ban is no longer a one-way door.
- **Unpin a post.** The pinned-posts strip now has an unpin control alongside pin.
- **Unread message badge.** The header Messages icon shows an unread count, so members see new direct messages at a glance.
- **Resend verification.** When a member's email is not yet verified, the composer offers a "Resend verification email" action instead of leaving them stuck.
- **Skills section is removable.** Like the other starter profile groups, the Skills group can now be deleted when it does not fit your community.
- **One Achievements tab.** The three separate gamification tabs on the profile are folded into a single Achievements tab.
- **Clearer field setup.** The add-field form now sets "Show on registration" directly and explains what each field type does.
- **Friendlier errors.** Error toasts stay on screen longer and are dismissible, instead of vanishing before you can read them.
- **Stay on the front end.** Members remain on the community front end after logging in and out, rather than being bounced to wp-admin or the WordPress login screen.

## A darker, more consistent dark mode

Dark mode now reaches the corners it used to miss: native form controls, skill chips, and leaderboard badges all follow the theme, and BuddyNext now tracks the BuddyX and Reign light/dark toggle so the community matches the rest of your site automatically.

## Quality fixes in this release

1.0.7 clears a batch of layout and interaction bugs found in testing:

- Turning off the desktop sidebar rail no longer leaves an empty column on hub pages; content reflows to full width.
- Member profiles with an empty sidebar no longer show a large blank gap on the right.
- The Spaces directory count now matches the spaces shown instead of also counting hidden sub-spaces.
- Profile action menus, the Share popover, and the Block and Report dialogs now close when you click outside them.
- The photo lightbox comment box matches the BuddyNext style and centres its empty state, and searching for a discussion to link now reports an error instead of silently showing nothing when the request fails.
- Reposting no longer fails silently, private images display in the lightbox for viewers allowed to see them, and the mobile bottom bar no longer covers Save and Cancel on the Profile Edit and Settings screens.
- The Pro **License** activation screen (Settings > License) loads its styles and scripts correctly on live hosts with a symlinked or non-standard document root, instead of leaving the page unstyled while its assets 404.

## In BuddyNext Pro 1.0.7

- **Continue Learning on the profile.** When Learnomy is connected, a member's profile shows a "Continue Learning" panel with their in-progress courses and a link back to the learning dashboard. Only the profile owner sees it.
- **Owner-only profile panels.** A new building block lets integrations add profile sections that only the profile owner can see, which is how Continue Learning is built.
- **Simpler white-label.** White-label is trimmed to the backend name and logo; per-space branding was removed as out of scope.

See the [Pro membership guide](../pro/01-membership-plans.md) for the wider Pro picture.

## Under the hood

For developers: 1.0.7 adds the `buddynext_private_community_can_access` filter for custom private-community access rules and the `buddynext_redirect_url` filter for controlling login and logout redirects. Pro ships a translation-ready i18n delivery standard for its stores and views, and the Notification and PageRouter layers had an audit pass that removed dead code and corrected dependency wiring. See the [developer guide](../developer-guide/03-architecture-overview.md) for the hook references.

The full, human-readable changelog for each plugin lives on its release-notes page: [BuddyNext release notes](https://wbcomdesigns.com/release-notes/buddynext/) and [BuddyNext Pro release notes](https://wbcomdesigns.com/release-notes/buddynext-pro/). Developers can also read the raw notes on each GitHub release: [BuddyNext 1.0.7](https://github.com/buddynext/buddynext/releases/tag/v1.0.7) and [BuddyNext Pro 1.0.7](https://github.com/buddynext/buddynext-pro/releases/tag/v1.0.7).

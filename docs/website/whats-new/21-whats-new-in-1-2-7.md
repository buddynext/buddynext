# What's New in 1.2.7

This release runs BuddyNext on multisite, opens BuddyNext to partner addons, makes hand-off moderation roles hold up inside spaces, and fixes what two QA rounds found. BuddyNext Pro 1.2.7 matches it on multisite and makes the automation screens do what they say.

> **Note:** BuddyNext free and BuddyNext Pro are released together. Update both at the same time and test them together.

## New for site owners

- **Multisite.** One community runs on the network's main site. Network Activate BuddyNext; nothing of it loads on a subsite.
- **Addons.** A partner addon lists its modules and BuddyNext wires each one in. See [Building an addon](../developer-guide/59-building-an-addon.md).
- **Profile panels.** The Portfolio tab and profile panels now live in BuddyNext, so any addon can add a panel to a member's profile.
- **Linked spaces.** A space can be linked to a partner object, such as a Learnomy course. Space settings shows a **Linked to** tab while the space has links, and **Remove** keeps every member.
- **Space opens on.** Space owners choose which of the space's tabs it opens on, including tabs added by addons.
- **Change a space's owner** from the Spaces list in wp-admin.
- **Stop emails like this.** Every optional email ends with a link to turn that email off. Welcome, verification and billing emails do not.

## Better for site owners

- **Roles inside spaces.** Post in spaces and Moderate spaces count a member's community role inside the spaces they belong to. A community Admin who is a plain member can still post when the rule is Moderators & up. Space settings and deleting a space stay with the space's own team. See [Roles and capabilities](../developer-guide/39-roles-and-capabilities.md).
- **Community Admin** follows **Review the moderation queue**, so a stricter **Moderate spaces** rule no longer locks community moderators out of it.
- **Who can create spaces** is set in one place, **Members > Roles & Capabilities**. The update moves a stricter earlier choice there.
- **Navigation Visibility** closes a section everywhere it appears: header menu, theme page list, sidebar, profile, mobile bar and post bylines, with a clear "not available" page.
- **Moderation.** Escalated reports show as escalated, community admins get a bell notification, members can report a space, and everyone who can decide appeals is told about a new one.
- **Emails** name who did what and where, use the community name everywhere and fit a phone screen.
- **Settings.** Export covers Pro settings and keeps secrets out; import reports what it applied and skipped. Each social login provider is one row, and the Save bar stays in view.
- **Themes.** BuddyNext keeps its own look on Astra, GeneratePress, Kadence and Blocksy, and with BuddyX or Reign the colour mode setting points to the theme's own setting.

## Fixes

- A comment on a forum discussion card sends one email, not two.
- Divi Theme Builder headers keep their styling on BuddyNext pages.
- A Terms page that is a draft, private, password protected or in the trash warns the owner instead of breaking sign-up.
- Settings warnings now show after saving instead of a plain "Settings saved".
- Editing a member with an invalid email address says so instead of keeping the old one.
- Report retention counts from when a report was closed.
- The plan-limit upgrade link when creating a space works.
- Reporting from a single post page confirms the report.
- Comment replies notify the parent comment's author once; a member who cannot see a post gets no mention notification.
- Scheduled posts, the Articles tab, the post editor's emoji picker, poll labels, media downloads, Safari's photo picker and video tiles in messages all behave as expected.

## For developers

- **Hooks.** `buddynext_register_addons`, `buddynext_addon_module_failed`, `buddynext_space_link_sources`, `buddynext_space_linked`, `buddynext_space_unlinked`, `buddynext_member_suite_panels` and `buddynext_export_option_prefixes`.
- **REST.** `GET members/{id}/portfolio`, `GET` and `DELETE spaces/{id}/links`; `/app/config` adds `profile_panels` and `card_types`.
- **Abilities.** The catalog lists Interact with posts and Dismiss reports, matching Roles & Capabilities.

## BuddyNext Pro 1.2.7

- Multisite: Pro loads only on the main site and is Network Activated.
- Learnomy course communities use the space-link service; older links move over on update.
- Members of a plan-gated space who lack the plan see the upgrade button.
- The upcoming events sidebar lists other members' events soonest first.
- Broadcasts never send to every member by mistake; drip sequences, bulk moderation, link rules and scheduled posts show honest results.
- The Pro portfolio route is removed; read `buddynext/v1/members/{id}/portfolio` instead.

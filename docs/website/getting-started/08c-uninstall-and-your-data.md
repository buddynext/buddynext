# Uninstall and Your Data

Deleting BuddyNext from the Plugins screen can do one of two things: remove the plugin's files and keep your community, or remove the files and everything BuddyNext stored. One setting decides which. This page explains that setting, what each choice removes and keeps, and what happens when you reinstall.

## Why this matters

A community holds months or years of members' posts, spaces and conversations. Deleting a plugin is easy to do by accident, or as a step in a reinstall. BuddyNext therefore keeps your data unless you have explicitly asked it not to, and asks you to confirm before you turn removal on.

## Where the setting lives

Go to **BuddyNext > Platform > Tools** and find **Data on uninstall**. The checkbox is **Delete all BuddyNext data when the plugin is uninstalled**.

- Deactivating BuddyNext never touches data, whatever this setting says. Only **Delete** on the Plugins screen reads it.
- BuddyNext Pro follows the same setting when you delete Pro.
- On a multisite network the community lives on the main site, so only the main site's choice counts.

## Turning removal on

When you tick the box and save, BuddyNext opens a confirmation that lists what a delete will remove and what it keeps. The save goes through only after you type **DELETE** into the field and press **Turn on data removal**. Press **Cancel** or Escape and nothing is saved.

With JavaScript switched off in your browser there is no dialog: the form shows a field instead, and the setting is saved only when you type **DELETE** into it. The server checks the word either way, so removal cannot be switched on without it.

Turning removal off again saves straight away, with no confirmation.

> **Note:** Take a database backup before you delete the plugin with removal on. A delete with removal on cannot be undone.

## What a delete removes and keeps

| | Removal off (default) | Removal on |
|---|---|---|
| Spaces, posts, comments, reactions, polls, notifications | Kept | Removed |
| Members' community data (profile fields, follows, connections, privacy and notification preferences) | Kept | Removed |
| BuddyNext settings, including Pro's | Kept | Removed |
| Community pages BuddyNext created (Activity, Members, Spaces and the others) | Kept | Removed |
| Your licence key and update cache | Kept | Removed |
| Scheduled background jobs | Removed | Removed |
| Uploaded files: avatars, covers and photos shared in posts | Kept | Kept |
| Financial records: invoices and subscriptions | Kept | Kept |
| WordPress user accounts | Kept | Kept |

Uploaded files stay in your uploads folder (the `bn-avatars`, `bn-covers`, `bn-space-avatars` and `bn-space-covers` folders) and photos stay where they were uploaded (the Media Library, or MediaVerse when it handles your media), so you can remove them yourself.

Financial records are always kept so your accounting history survives any uninstall.

## Reinstalling after a delete

- **Removal was off:** install and activate BuddyNext again and your community is back as you left it. Your licence key is still saved, so updates keep working. The plugin shows the licence status it last saw until its weekly licence check refreshes it.
- **Removal was on:** BuddyNext starts fresh, as on a new site. Run the setup wizard again and re-enter your licence key. Uploaded files and financial records from before are still on the site.

## Deleting a member is a different thing

This page is about deleting the plugin. Deleting one member's account (by an admin, or through a WordPress privacy erasure request) always removes that member's community data straight away, whatever the uninstall setting says. Two things are deliberately kept:

- **Financial records** tied to the member's purchases, where the law may require you to keep them.
- **Spaces the member owned.** A space belongs to its community, so ownership passes to its longest-serving moderator, or a site admin, instead of the space being deleted.

## Related

- [Tools and Maintenance](08-tools-and-maintenance.md) - the Tools screen where this setting sits.
- [Multisite](08b-multisite.md) - how BuddyNext runs on a network's main site.
- [Privacy and Data](../accounts-access/08-privacy-and-data.md) - data export and erasure for members.

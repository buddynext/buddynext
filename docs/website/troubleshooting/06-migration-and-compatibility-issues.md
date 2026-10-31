# Migration and Compatibility Issues

Problems moving a BuddyPress or BuddyBoss community into BuddyNext, and running BuddyNext next to the plugin you are replacing.

## BuddyPress or BuddyBoss is still active alongside BuddyNext

**Symptom:** Screens look doubled, members see two community experiences, or a page behaves like the old plugin.

**Likely cause:** BuddyPress and BuddyBoss are supported as a migration source only. Running either alongside BuddyNext on a live site is not supported.

**Fix:** Do the work on a staging copy. When you are ready to go live, announce the switch and deactivate the old plugin at that point. Do not leave both on production as a way of testing.

See [Moving your existing members and content](../migrating-from-buddypress/04-migrating-your-data.md).

## The importer says there is nothing to import

**Symptom:** BuddyNext Importer finds no source, or reports far less than you expected.

**Likely cause:** The importer reads the old community's tables straight from your database. If those tables are gone, it has nothing to read. It picks the source it finds, BuddyBoss first and then BuddyPress.

**Fix:**
1. Make sure the old plugin's data is still in the database, and that you are running the importer on a copy that has it.
2. Open **Tools > Import to BuddyNext** and read the source panel. It lists what was found and the content the importer cannot carry.
3. From WP-CLI, `wp buddynext-import stats` shows what the source holds and moves nothing.

## Forums, media or messages were skipped

**Symptom:** The report says a domain was skipped, and your forums, albums or private messages are missing.

**Likely cause:** Some domains need a companion plugin active at the destination. Forums need Jetonomy, and media and private messages need WPMediaVerse. A domain whose destination plugin is not active is skipped, and the importer says so.

**Fix:** Activate the companion plugin, then run the importer again. Re-running does not create duplicates.

## I need to know whether everything arrived

**Fix:** Read the **What was imported** table. Every domain reports what it wrote against what the source held, with a plain-sentence reason for anything it did not write. A domain you unticked reads "skipped by choice". Then press **Run checks** under **Check the migration**, or run `wp buddynext-import verify`. Finally, look at the community as a member would: open a private space, a migrated discussion and a member profile.

## I ran the import and want to undo it

**Likely cause:** An import cannot be undone from inside the plugin.

**Fix:** Restore a backup. This is why the documented route is to rehearse on a staging copy first, read the report, and only then repeat on the live site with a backup you have tested restoring.

## Re-running the import, and when to clean up

Re-running never duplicates anything, because the importer keeps temporary working tables that remember what it already moved. Remove them last, with **Remove import data** on the same screen or `wp buddynext-import cleanup`, once you are completely finished. Clearing them early removes the protection against a second run creating duplicates.

## Moving from a hosted platform

The importer covers WordPress-native sources only. For Circle, Mighty Networks, Skool or Discourse there is no importer: you export what the platform lets you export, invite members to new accounts and rebuild the content, or ask the team to do it for you. See the [migration overview](https://buddynext.com/migrate/).

## Related

- [Moving your existing members and content](../migrating-from-buddypress/04-migrating-your-data.md)
- [What changed for members](../migrating-from-buddypress/05-what-changed-for-members.md)
- [Concept glossary](../migrating-from-buddypress/02-concept-glossary.md)
- [Install and Setup Issues](01-install-and-setup-issues.md)

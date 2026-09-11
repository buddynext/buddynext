# Recipe: Migrate from BuddyPress or BuddyBoss

**Goal:** move an existing BuddyPress or BuddyBoss community onto BuddyNext without losing members or content.
**You'll need:** BuddyNext Free (Pro optional, for the paid and advanced features afterward).
**Time:** depends on community size; plan a maintenance window for the data step.

## Before you start

- Take a full backup of your site and database. This is not optional.
- Read what maps to what first - BuddyNext uses different names (spaces, not groups) and a different model in places, so knowing the vocabulary prevents surprises.
  Start with [BuddyNext vs BuddyPress](../migrating-from-buddypress/01-buddynext-vs-buddypress.md) and the [Concept Glossary](../migrating-from-buddypress/02-concept-glossary.md).

## Steps

1. **Check feature parity.** Confirm the BuddyBoss or BuddyPress features you rely on have a BuddyNext equivalent, and note anything that works differently.
   Full guide: [BuddyBoss Feature Parity](../migrating-from-buddypress/03-buddyboss-feature-parity.md).

2. **Run the data migration.** Bring members, profiles, groups, and content across with the migration tooling, on your backed-up staging copy first.
   Full guide: [Migrating Your Data](../migrating-from-buddypress/04-migrating-your-data.md).

3. **Place your community pages.** Point your menus and pages at the BuddyNext feed, directory, and spaces so members land where they expect.
   Full guide: [Shortcodes and Placement](../getting-started/05-shortcodes-and-placement.md).

4. **Brand it to match.** Set colours and logo so the switch feels like an upgrade, not a different site.
   Full guide: [Appearance and Branding](../getting-started/07-appearance-and-branding.md).

5. **Tell your members what changed.** A few things look different for members after the move; point them at the summary so they are not confused on first login.
   Full guide: [What Changed for Members](../migrating-from-buddypress/05-what-changed-for-members.md).

## What your members see

Members keep their accounts, profiles, and connections. Groups become spaces, the activity stream becomes the feed, and the profile and directory carry over. The [What Changed for Members](../migrating-from-buddypress/05-what-changed-for-members.md) page is written to hand straight to them.

## Related

- [Start Here](../getting-started/00-start-here.md) - the full map of what you now have
- [Moderate at Scale](03-moderate-at-scale.md) - set up moderation on the migrated community
- [Migrating Your Data](../migrating-from-buddypress/04-migrating-your-data.md) - the full data reference

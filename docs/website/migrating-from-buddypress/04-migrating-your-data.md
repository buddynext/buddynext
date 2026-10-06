# Moving Your Existing Members and Content

This page is the honest picture of what happens to your existing community when you switch to BuddyNext, what carries over automatically today, and the practical way to make the move smooth for you and your members.

We would rather set the right expectation up front than have you discover a surprise later.

## What carries over automatically

- **Your WordPress member accounts stay exactly as they are.** BuddyNext works on top of standard WordPress users. Everyone who already has a login keeps it - same username, same email, same password, same role. Nobody has to re-register.
- **Your WordPress roles and capabilities stay in place.** Admins remain admins, editors remain editors, and so on. BuddyNext respects your existing WordPress roles.
- **Your regular WordPress content is untouched.** Posts, pages, media in your media library, and other standard WordPress content are not affected by activating BuddyNext.

In short: the people are already there, and their accounts work on day one.

## What the importer brings across

Your community-specific data lives in BuddyPress or BuddyBoss storage, and BuddyNext keeps its own. The two are separate systems, so BuddyNext does not read your old data on its own - but **BuddyNext Importer** does. It is a free companion plugin, and it is the recommended route for any community with history worth keeping.

It reads the old community's tables straight from your database and writes into BuddyNext through BuddyNext's own services. It moves thirteen domains, all selected by default:

- Their **profile fields and the values** members filled in, and **member types**
- **Groups**, which become Spaces, keeping their privacy setting and member roles, plus group types as space categories
- **Activity history**, with its comment threads intact
- **Friendships**, **follows**, **reactions** and saved **bookmarks**
- **Forums** from bbPress, with topics, replies and topic tags (needs Jetonomy)
- **Avatars and cover images**
- **Albums and media**, including rtMedia photos and videos (needs WPMediaVerse)
- **Private messages** (needs WPMediaVerse)

A domain whose destination plugin is not active is skipped and the importer says so.

Two nice details: a BuddyBoss group album arrives as a space-owned album rather than as one member's personal album, and blog activity comes across as BuddyNext's article type with its comment thread.

**It tells you exactly what arrived.** Every domain reports what it wrote against what the source held, and gives a plain-sentence reason for anything it did not write. A domain you chose to leave out reads "skipped by choice", never as a shortfall. Migration checks run from the admin screen (the **Check the migration** card, with a **Run checks** button), so you do not need WP-CLI to verify the result. Before a run starts, the source panel names the content this importer cannot carry, per kind, so there are no surprises afterwards.

### How to run it

1. Install and activate **BuddyNext Importer** on your staging copy, with BuddyNext active. Your BuddyPress or BuddyBoss data must still be in the database; the importer picks the source it finds (BuddyBoss first, then BuddyPress).
2. Go to **Tools > Import to BuddyNext**.
3. Read the source panel. It lists what was found and the content this importer cannot carry.
4. In **What to import**, leave everything ticked, or untick what you want to leave behind.
5. Press **Start import**, or **Run in background** to let it keep going while you leave the page.
6. When it finishes, check the **What was imported** table, then press **Run checks** under **Check the migration**.
7. Look at the community as a member would: open a private space, a migrated discussion, a member profile.
8. Repeat on the live site, with a backup you have tested restoring.
9. When you are completely finished, deactivate and delete the importer. **Remove import data** on the same screen (or `wp buddynext-import cleanup`) clears its temporary working tables. Do this last: those tables are what stop a second run from creating duplicates.

From WP-CLI the same steps are:

| Command | What it does |
|---|---|
| `wp buddynext-import stats` | Shows what the source holds. Moves nothing. |
| `wp buddynext-import migrate-all` | Runs every selected domain in order. Safe to re-run. |
| `wp buddynext-import verify` | Counts the source again, compares it with what arrived, and spot-checks random items. `--samples=<n>` sets how many of each kind. |
| `wp buddynext-import cleanup` | Removes the importer's temporary tables. Run it last. |

`migrate-all` takes `--only` and `--skip` for the same per-domain choice - for example `wp buddynext-import migrate-all --skip=messages,media` or `wp buddynext-import migrate-all --only=reactions` (which pulls in its parent domains automatically). There is also one command per domain, such as `migrate-spaces` and `migrate-activity`.

> **Rehearse on a staging site or a local copy first.** Re-running never duplicates anything, but an import **cannot be undone from inside the plugin** - reversing it means restoring a backup. Practise the run somewhere safe, read the report, then do it for real.

Whichever route you take, your old data is not deleted or harmed by the move.

## The alternative: a clean start

Not every community wants its history. If your old groups are mostly dormant, or the activity stream is years of noise you would rather not carry, a clean start is a legitimate choice and often a quick one - your accounts come across regardless, so only the community-specific setup is left. Here is the practical playbook.

### 1. Re-create your key spaces

Make a short list of the groups that actually matter - usually a handful, not all of them - and re-create them as Spaces in BuddyNext. Most communities discover that a few well-run spaces work better than a long list of dormant ones, so this is a good moment to prune.

### 2. Set up your profile fields

Add the custom profile fields you want members to fill in. Keep the list short and meaningful - members are far more likely to complete a focused profile than a long form. You can always add more fields later.

### 3. Seed the feed before you invite everyone

Post a welcome message and a few starter posts so the feed does not look empty on day one. A community that already has a little life in it invites people to join in; a blank wall does not.

### 4. Communicate with your members

Tell your members what is happening before they notice it themselves. A short announcement goes a long way. Cover:

- That the community has moved to a new, faster experience.
- That their login still works - no need to re-register.
- That they may want to set up their profile again and re-join the spaces that matter to them.
- Where to find messages, notifications, and their groups (now called spaces) in the new layout.

You can hand your members the What Changed for Members page, which is written for exactly this purpose.

### 5. Retire the old community plugin

BuddyPress and BuddyBoss are a migration source only, and running them alongside BuddyNext is not supported. Set up BuddyNext on a staging copy, announce the switch, then deactivate the old plugin when BuddyNext goes live.

## Good to know

- **Nobody has to create a new account.** This is the single biggest worry for most owners, and the answer is reassuring: existing WordPress logins keep working.
- **Your old data stays safe in the previous system** until you decide what to do with it. Switching to BuddyNext does not erase it.
- **A clean start is often a feature, not a chore.** It is a chance to drop dormant groups, tidy your profile fields, and relaunch with momentum.
- **You do not have to choose blindly.** Run the importer on a staging copy, read its report of what arrived, and then decide whether to import for real or start clean.

## What's next

- See [What Changed for Members](05-what-changed-for-members.md) for a friendly page you can share with your community.
- See the [Concept Glossary](02-concept-glossary.md) so your members recognize the new names for familiar features.
- See [Does BuddyNext Replace BuddyPress?](01-buddynext-vs-buddypress.md) for how the two platforms relate.

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

> **Coming from Mighty Networks, Circle, Skool or Discourse?** The importer reads BuddyPress and BuddyBoss only. See [Assisted migration from Mighty Networks, Circle, Skool and Discourse](06-assisted-migration-from-hosted-platforms.md).

### Before you start

Work through this list first. Each item removes a way for the run to go wrong.

1. **Take a full backup and test that you can restore it.** An import cannot be undone from inside the plugin. Restoring a backup is the only way back.
2. **Work on a staging copy.** Copy the live site (files and database) to a staging site or a local copy. Do the first run there.
3. **Check your versions.** BuddyNext needs WordPress 6.9 or higher and PHP 8.1 or higher.
4. **Keep the old data in the database.** The importer reads BuddyPress or BuddyBoss tables directly. If you have already deleted that plugin's data, there is nothing to read. Deactivating the old plugin is fine. Deleting its data is not.
5. **Activate BuddyNext.** The importer writes through BuddyNext's own services, so BuddyNext must be active.
6. **Activate the companion plugins you need.** Forums need Jetonomy. Albums, media and private messages need WPMediaVerse. A domain whose destination plugin is not active is skipped.
7. **Decide what you want to leave behind.** Every domain is ticked by default. If you want a clean start for some of it, such as years of old activity, untick it on the run screen.
8. **Do not run BuddyPress or BuddyBoss alongside BuddyNext on the live site.** They are a migration source only. Keep the overlap on staging.

### Where the importer lives

BuddyNext Importer is a separate, free plugin. It is not part of the BuddyNext download, so you install it yourself on the site that holds your old data.

Once it is active, the screen is at **Tools > Import to BuddyNext** in wp-admin. The same work is available from WP-CLI under `wp buddynext-import`. The two routes do the same thing, so use whichever you prefer.

The importer picks the source it finds: BuddyBoss first, then BuddyPress. If both are present, BuddyBoss wins.

### The screens, in order

The **Import to BuddyNext** screen reads from top to bottom.

| Screen area | What it shows | What you do |
|---|---|---|
| Source panel | What the importer found in your database, and the content it cannot carry, listed per kind | Read it before anything else. It tells you what will not come across. |
| **What to import** | One tickbox per domain, all ticked | Leave everything ticked, or untick what you want to leave behind. |
| **Start import** and **Run in background** | The two ways to begin | Press **Start import** to watch it run. Press **Run in background** to let it continue while you leave the page. |
| **What was imported** | A table with one row per domain: what was written against what the source held, with a plain-sentence reason for any gap | Read every row once the run finishes. |
| **Check the migration** | A **Run checks** button | Press it to count the source again and compare it with what arrived. |
| **Remove import data** | Clears the importer's temporary working tables | Use it last, once you are completely finished. |

### What each domain does

The importer writes into BuddyNext through BuddyNext's own services, so imported content behaves like content created in BuddyNext. The mapping is:

| What you had | What it becomes in BuddyNext |
|---|---|
| Profile fields and the values members filled in | Profile fields and values. Member types come across with them. |
| Groups | Spaces. Each keeps its privacy setting and its member roles. |
| Group types | Space categories |
| Activity items and their comments | Feed posts, with the comment threads intact |
| Blog activity | BuddyNext's article post type, with its comment thread |
| Friendships | Connections |
| Follows | Follows |
| Reactions and saved items | Reactions and bookmarks |
| bbPress forums, with topics, replies and topic tags | Discussions in Jetonomy (needs Jetonomy) |
| Avatars and cover images | Member and space images |
| Albums and media, including rtMedia photos and videos | WPMediaVerse albums and media (needs WPMediaVerse) |
| BuddyBoss group albums | Albums owned by the space, not by one member |
| Private messages | WPMediaVerse messages (needs WPMediaVerse) |

Three notes on the mapping:

- **Reactions and bookmarks depend on their parents.** A reaction needs the post it was left on. If you run only the reactions domain, the importer pulls in the domains it depends on automatically.
- **Your member accounts are not imported.** They already exist as WordPress users. The importer attaches profile data, connections and spaces to those accounts.
- **Anything in the source panel's "cannot carry" list is not moved.** Plan for it before you cut over, not after.

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

### Running it again safely

You can run the importer more than once. This is useful after you add a missing companion plugin, or when you repeat the run on the live site.

- **Re-running does not create duplicates.** The importer keeps temporary working tables that remember what it already moved.
- **Do not remove the import data between runs.** **Remove import data** (or `wp buddynext-import cleanup`) clears those tables. Once they are gone, the protection against duplicates goes with them. Do it only when you are completely finished.
- **To add a skipped domain, activate its plugin and run again.** For example, activate Jetonomy and run the importer to bring the forums across. Domains already moved are not repeated.
- **To run one domain, use the tickboxes or the CLI.** `wp buddynext-import migrate-all --only=reactions` runs one domain and its parents. `--skip=messages,media` leaves two out. Each domain also has its own command, such as `migrate-spaces` and `migrate-activity`.
- **A re-run is not an undo.** It never removes content. If you ticked the wrong things, restore your backup and start again.

### Handling a large community

If your community has a lot of history, press **Run in background** on the screen so the run does not depend on an open browser tab. From WP-CLI, `wp buddynext-import migrate-all` runs without a browser at all. Rehearse on staging first so you know how long it takes before you plan the live cut-over.

## What to check after the run

Do the checks in this order. Each one catches a different kind of problem.

1. **Read the What was imported table.** Every domain shows what it wrote against what the source held. A domain you unticked reads "skipped by choice". Anything else that is short has a plain-sentence reason beside it.
2. **Press Run checks.** Under **Check the migration**, the button counts the source again, compares it with what arrived, and spot-checks random items. From WP-CLI, run `wp buddynext-import verify`. Add `--samples=<n>` to change how many of each kind it spot-checks.
3. **Look at the community as a member would.** Log in as a normal member, not as an administrator. Open a private space, a migrated discussion and a member profile. A private space is the best test, because it shows that privacy and roles came across.
4. **Check the spaces.** Confirm the privacy setting on a few spaces and that their owners and moderators kept their roles.
5. **Check the profile fields.** Open a profile that you know was filled in and confirm the values are there.
6. **Check messages and media if you moved them.** Open a conversation and an album. Check that a group album shows as owned by its space.
When all of that passes on staging, repeat the run on the live site with a fresh, tested backup.

## Common problems

| Problem | Likely cause | What to do |
|---|---|---|
| The importer says there is nothing to import | The old plugin's tables are gone, or you are on a copy that does not have them | Run it on a copy that still has the data. Read the source panel, or run `wp buddynext-import stats`, which moves nothing. |
| Forums, albums or messages are missing | The destination plugin was not active, so the domain was skipped | Activate Jetonomy (forums) or WPMediaVerse (media and messages), then run again. |
| Counts are lower than the source | Some content is on the "cannot carry" list, or a domain was unticked | Read the reason beside that domain in the What was imported table. |
| Both BuddyPress and BuddyNext look active | The old plugin was left on after the move | Deactivate it when BuddyNext goes live. Running both on a live site is not supported. |
| You want to undo the import | Imports cannot be undone from inside the plugin | Restore your backup. |

More detail is in [Migration and Compatibility Issues](../troubleshooting/06-migration-and-compatibility-issues.md).

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

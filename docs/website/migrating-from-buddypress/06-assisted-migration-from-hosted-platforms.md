# Assisted migration from Mighty Networks, Circle, Skool and Discourse

The self-serve BuddyNext Importer covers BuddyPress and BuddyBoss only. It reads those plugins' tables from your own database. Hosted platforms keep their data on their own servers, so the importer cannot reach it.

If you are moving off a hosted platform, there are two routes. For Mighty Networks, our team runs a done-for-you migration through the Mighty Networks API. For Circle, Skool and Discourse, there is no importer and no API migration, so you rebuild with our help if you want it.

For exactly what the self-serve importer moves, see [Moving Your Existing Members and Content](04-migrating-your-data.md).

## Which route applies to you

| You are moving from | Route |
|---|---|
| BuddyPress or BuddyBoss | Self-serve BuddyNext Importer |
| Mighty Networks | Assisted migration through the Mighty Networks API |
| Circle, Skool or Discourse | Export what the platform allows, rebuild, and invite by CSV |

## Mighty Networks

Our team runs this migration for you. You do not run a tool yourself.

### What you hand over

You give us two things:

- An **API key** for your Mighty Networks account.
- Your **network ID**.

### What moves

The migration moves:

- **Members**, with their profile answers, avatars and badges.
- **Spaces.**
- **Posts, polls, comments and reactions.**
- **Events and RSVPs.**
- **Courses with their lessons.**
- **Linked images and documents.**
- **Plans.**

### How members are matched

Members are matched by the email address they used on Mighty Networks. Each member is then placed on their plan, based on the payment records. Being on a plan opens the spaces and courses that plan gives access to.

### Where content lands

Content lands across BuddyNext and the companion plugins:

| Content | Where it goes |
|---|---|
| Members, spaces, posts, polls, comments and reactions | BuddyNext |
| Courses and lessons | Learnomy |
| Events and RSVPs | Eventonomy |
| Images and documents | WPMediaVerse |

### What does not move

Be clear about these before you start:

- **Direct messages.** Mighty Networks has no API for them, so they cannot be moved.
- **Videos hosted on Mighty Networks.** These are kept as links back to where they are hosted. They are not copied across.
- **Files that no post or lesson links to.** The migration follows links from content. A file that nothing points to is not found.
- **Plan prices.** You confirm the prices for each plan with us. They are not assumed.

### Passwords and billing

Two points need to be stated plainly.

- **Passwords do not carry over.** Members set a new password on BuddyNext. Plan your announcement around that.
- **Access moves with the member. Billing is agreed with you.** A member is placed on their plan, which gives them their spaces and courses. How renewals continue is agreed with you before cutover. Do not assume an existing subscription keeps billing on its own.

### The process

1. **Count.** We count what you have: members, spaces, posts, events, courses and files.
2. **Agree the structure.** We agree with you how your spaces, courses and plans map onto BuddyNext and the companion plugins.
3. **Build on staging.** We set the structure up on a staging site, not on your live site.
4. **Import.** We run the import through the API into staging.
5. **Clean.** We fix what the import shows up, such as missing links or odd formatting.
6. **Check as a real member.** We log in as a member on a plan and confirm that the right spaces and courses open.
7. **Cut over.** We switch the live site, with a final catch-up import so that nothing posted during the checks is lost.

See the plan page for Mighty Networks: [Migrate from Mighty Networks](https://buddynext.com/migrate/mighty-networks/).

## Circle, Skool and Discourse

There is no importer and no API migration for these platforms. The route is a rebuild, done in stages.

1. **Export what the platform allows.** Export your member list with email addresses and anything else the platform lets you download. What you can export depends on the platform and your plan with them.
2. **Rebuild the spaces on staging.** Create your spaces on a staging copy of the BuddyNext site. Keep the list short. This is a good time to drop spaces that nobody uses.
3. **Invite members by CSV.** Upload your member list as a CSV from the Invites screen (see below).
4. **Run both platforms for a while.** Keep the old community open while members move. Close it when most of your active members have joined.

Our team can help with any of this. Send your enquiry to [the migration contact form](https://buddynext.com/contact/?topic=migration).

Plan pages for each platform:

- [Migrate from Circle](https://buddynext.com/migrate/circle/)
- [Migrate from Skool](https://buddynext.com/migrate/skool/)
- [Migrate from Discourse](https://buddynext.com/migrate/discourse/)

### Bulk-inviting members by CSV

Go to **BuddyNext > Members > Directory > Invites** and find **Send bulk invitations**. Choose a **CSV file** where each line is `email` or `email,first_name`, then choose **Send invitations**.

Some rules to know:

- Up to **500 rows** are processed per upload. Split a longer list into several files. A developer can change the cap with the `buddynext_invite_csv_max_rows` filter.
- An address that already has a pending invite is skipped, so uploading a longer list again does not invite anyone twice.
- Invitations expire after 7 days by default. You can resend an expired one.
- Sending invitations needs the WordPress manage options capability, so administrators send them.

Invitations work in any registration mode. In Invite Only mode, a valid invitation is required to join. The full walkthrough is in [Inviting People to Your Community](../accounts-access/10-invitations.md).

Members create a new account from their invitation. Nothing carries over from the old platform automatically, including their password, content and subscription state.

## Before you commit to a date

- Decide what you want from the old platform and what you are happy to leave behind.
- Tell your members early. Explain that they will receive an invitation email and need to set a password.
- Keep the old platform running until you have checked the new one as a real member.
- If members pay you today, decide how that will work on BuddyNext before the switch. For Mighty Networks, this is part of the agreement with our team.

## Related

- [Moving Your Existing Members and Content](04-migrating-your-data.md) - the BuddyPress and BuddyBoss importer.
- [What Changed for Members](05-what-changed-for-members.md) - a page you can share with your community.
- [Migration and Compatibility Issues](../troubleshooting/06-migration-and-compatibility-issues.md)

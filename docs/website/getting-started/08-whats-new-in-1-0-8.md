# What's New in 1.0.8

BuddyNext 1.0.8 is a truth-telling release. Its theme is that every control the product shows you should actually do what it says: a toggle that changes nothing is worse than no toggle at all, because you configure it, believe it, and are wrong. Several controls that quietly did nothing now either work or are gone. Alongside that, members get real safety tools where they had none, and owners get control of two menus they previously could only change in code.

> **Note:** BuddyNext free and BuddyNext Pro are released together. If you run both, update them at the same time so they stay in step.

## Own the header avatar menu

The menu behind your avatar in the header was the last navigation surface with no admin control. It is now the **Account Dropdown** scope in **Settings > Navigation**, alongside Main Navigation, Profile Tabs, Space Tabs, and the Mobile Bottom Nav.

That means you can hide a link you do not want (Bookmarks, say), rename one to the words your community uses, drag the menu into a different order, and limit an entry to admins or to a capability you name - all from the same screen you already use for the rest of the navigation. Links a companion plugin adds to the menu turn up in the manager on their own.

**Log Out** is fixed and always shown. Hiding it would strand every member with no way to sign out, so it does not carry a hide toggle.

## Reorder the mobile bottom bar

The Mobile Bottom Nav rows now have drag handles, like every other navigation scope, and the bar on the phone honours the order you save. Before this, the list looked reorderable and was not.

The centre **Create** button and the final **Profile** slot stay pinned - Create is only dead-centre while it has the same number of tabs on either side of it. Feed, Spaces, Alerts, and anything a plugin adds move freely around them.

## "Require approval to join" now appears only where it works

A space's **Require approval to join** toggle was drawn on all three space types, but it only ever acted on **Open** spaces. Switch it on for a private or secret space and it saved, showed as on, and changed nothing.

It is now shown on Open spaces only, where it does exactly what it says: the space stays readable to everyone, and a Join click becomes a request you approve. On the other two types the settings panel explains why there is no toggle instead of leaving a hole:

- A **private** space already sends every join through the approval queue.
- A **secret** space is invite only, so there are no join requests to approve.

Nothing has changed about how private or secret spaces behave. Only the control that misrepresented them is gone. See Managing Space Members.

## Move a scheduled post, and schedule in your own clock

Two changes to scheduling:

- **Reschedule.** A scheduled post's time used to be final - the only way to move it was to delete the post and write it again. Now, while a post is still scheduled, its edit form carries a **Scheduled for** control: change the date and time and the post publishes at the new moment. A post that has already published has no such control, so editing can never pull a live post back out of the feed.
- **Site time, named.** Every schedule control now reads and writes in the timezone set at **Settings > General** in WordPress, and prints that zone in its label ("Publish at (Europe/Berlin)"). An author in another timezone used to type 12:50 and watch the post card answer 7:20 am - the same instant, two numbers, which reads as a bug.

## Members can report media, and block whoever posted it

Until now, a BuddyNext site had no way for a member to report a photo or a video. Not a hidden one - none. BuddyNext's lightbox replaces the media page that would otherwise carry those controls, and the lightbox offered only Favorite, Share, and Download.

The photo lightbox now carries **Report** and **Block** on anyone else's media:

- **Report** takes a reason (spam, harassment or hate speech, nudity or sexual content, violence or graphic content, copyright infringement, or other) and a short note, and files into the media report queue your moderators already work. There is no second queue to check.
- **Block** blocks the *member* who uploaded the media, not the file.

Both are hidden on your own media and for logged-out visitors. See Profile Media and Albums.

## Profile fields that keep their promises

- **"Display as" on a date field now reduces what is published.** The control offered Full date, Month + Year, Year only, and Calculated age - and every date rendered as the full stored date anyway. On a birthday field that meant an owner who deliberately chose "Age only" was publishing members' full dates of birth and had no way to notice. It now applies, everywhere the value is read.
- **"Searchable" now means searchable.** Ticking Searchable only ever did something on a Public field. On a Members-only field it silently did nothing: no index entry, no warning. A searchable Members-only field is now matched for signed-in members and never for a stranger. Fields limited to followers, connections, or the member alone are still never indexed. See Custom Profile Fields.
- **A required field you cannot see can no longer lock you out.** Limiting a group to one member type and marking a field in it required told every member of every other type that a field was missing - one they could not see and could never fill in, so they could not save their profile again at all.
- **Choice values are found by their label.** A dropdown or multi-select option was indexed as an internal slug, so "French Horn" was unfindable everywhere, and accented options (for example "Flügelhorn") were permanently unfindable on non-English sites.

## Smaller things members and owners will notice

- **The new-posts pill is capped at 99+.** It used to print the raw number, so a busy feed shouted "3,412 new posts" - which reads as overwhelming rather than alive.
- **A private space's About tab is readable by non-members.** Name, description, house rules, and the moderator list are public on a private space, as they were always meant to be, so someone can see who is in charge and what the rules are before asking to join. The feed stays gated.
- **The directory's "online only" filter survives a sort.** Ticking it and then sorting used to quietly drop it while the checkbox stayed ticked.
- **A member card no longer offers to mute someone you already muted.** The action used to un-mute the person it had just offered to mute.
- **A space invite is reachable by keyboard.** You can now open the space you have been invited to before deciding, rather than only being able to Accept or Decline.
- **The space sidebar card is named for what it holds.** It said "Moderators" while the owner sat inside it.
- **Publish Now publishes now.** Using Pro's Publish Now on a scheduled post used to send it live at the moment it was originally *composed*, so a post written on the 1st and released on the 5th appeared buried under four days of feed. It now lands at the top, where the button's name promises.
- **A ceiling on connections.** A member can hold up to 5,000 connections, matching the existing 5,000 ceiling on following. The limit is checked when a request is accepted, on both members.

## Under the hood

1.0.8 also carries a large scale and correctness pass that owners of big communities will feel rather than see: indexes on the tables that actually grow, bounded queries where lists were previously loaded whole, cache invalidation that no longer clears every member's cache when one connection is accepted, and webhook delivery that fans out per endpoint instead of blocking on one slow receiver.

The full, human-readable changelog for each plugin lives on its release-notes page: [BuddyNext release notes](https://wbcomdesigns.com/release-notes/buddynext/) and [BuddyNext Pro release notes](https://wbcomdesigns.com/release-notes/buddynext-pro/).

# What's New in 1.3.0

This release makes your partner plugins show up in the community as live cards, brings Eventonomy 2.0 events into your Spaces and plans, and keeps the feed, Space delete and big-Space delivery fast on large sites.

> **Note:** BuddyNext free and BuddyNext Pro are released together. Update both at the same time and test them together.

## Better for site owners

- **Live partner cards.** Discussions from Jetonomy, badges from WB Gamification and documents from MediaVerse show their facts as they are now, with up to two buttons. With BuddyNext Pro, job, resume, listing, course and event cards do the same.
- **Events follow your Spaces.** With Eventonomy 2.0, an event can be members-only or open to a Space's members. People who may not open it see a preview with the right button for their situation, and a plan-gated Space uses its own membership wording.
- **Space Location.** A Space can have an optional Location that Space search matches and the About tab shows.
- **Members list.** Filter and export by member type, and the CSV follows the Active or Suspended tab.
- **Uninstall safety.** Switching on the uninstall data wipe needs a typed confirmation, and a settings import cannot switch it on.

## Better for members

- **Discussions tab.** A Space's Discussions tab shows Pinned, Solved and Closed and up to two tags on each topic.
- **Load more.** The Space and profile Media tabs, albums and album pictures page with Load more, so a large library is fully reachable.
- **Open to Work** (BuddyNext Pro with Career Board). The card shows skills, place, experience and photo, follows the resume's access setting, and stays current when the photo changes.

## Faster on large sites

- Posting in a big Space delivers in fixed-size batches.
- Deleting a forum Space removes its topics' feed cards and search rows.
- The For you feed stays fast for members with many connections or interests.
- Background jobs that continue in chunks no longer queue the same next chunk twice, and the upgrade clears duplicates older versions left queued.

## Fixes

- A guest no longer learns of private pictures over REST, and a suspended member's Media tab shows nothing.
- The albums grid on a phone no longer scrolls sideways when a title is long, and album titles show apostrophes and dashes as typed.
- Outlined buttons, form fields, chips, switches and muted text meet WCAG contrast in light and dark mode.
- Emails are built in the recipient's language.

## Developers

- `buddynext_card_live`, `buddynext_card_facts` and `buddynext_card_actions` let a bridge supply live card values. See [Extending Cookbook](../developer-guide/41-extending-cookbook.md).
- `buddynext_safeguard_check` passes what is being written as a sixth argument.

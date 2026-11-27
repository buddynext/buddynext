# What's New in 1.3.0

This release makes your partner plugins show up in the community as live cards, brings Eventonomy 2.0 events into your Spaces and plans, and keeps the feed, Space delete and big-Space delivery fast on large sites.

> **Note:** BuddyNext free and BuddyNext Pro are released together. Update both at the same time and test them together.

## Better for site owners

- **Live partner cards.** Discussions from Jetonomy, badges from WB Gamification and documents from MediaVerse show their facts as they are now, with up to two buttons. With BuddyNext Pro, job, resume, listing, course and event cards also show live facts; their title opens the item, a course card adds View certificate when the member has one, and an event card keeps its Going and Maybe buttons.
- **Events follow your Spaces.** With Eventonomy 2.0, an event can be members-only or open to a Space's members. People who may not open it see a preview with the right button for their situation, and a plan-gated Space uses its own membership wording.
- **Space Location.** A Space can have an optional Location that Space search matches and the About tab shows.
- **Members list.** Filter and export by member type, and the CSV follows the Active or Suspended tab.
- **Uninstall safety.** Switching on the uninstall data wipe needs a typed confirmation, and a settings import cannot switch it on.

- **Articles in one place.** The Member Blog Space tab is now called Articles, the writing page is Write an article, and the settings hints use the same word. The Articles in Spaces switch follows the Navigation switch as you click.
- **Comments on articles stay in step.** Spam, trash, delete and approve on an article reach its feed card, and edits and deletes on the card reach the article. A delete on the card moves the article's copy to the trash.
- **Fewer surprise cards.** Editing an article that is already public never posts it to the feed again, and a member who verifies their email gets cards for their 20 newest articles.
- **Learnomy links are owner-only.** Only a site administrator or the course's own author can link a community to a course, and the community must be private or secret. A community you switch off is not shown to learners, and switching it on adds everyone who enrolled meanwhile (BuddyNext Pro).
- **Jetonomy 2.1.0 or newer.** Space discussions need Jetonomy 2.1.0. Below it the Discussions tab, forum provisioning and forum linking are off, your forums and posts are kept, and Integration Settings says which version turns them on.
- **Learnomy keeps what learners earned.** A learner keeps a course community while another access source remains, and a revived or renewed plan gives its courses back. Saving a plan keeps linked draft, archived or beyond-200 courses, renewing a certificate updates the one post, and renaming a course updates its existing posts in place (BuddyNext Pro).
- **Honest Learnomy status.** A paused Learnomy is shown as not running and its bridge stands down. The Community for banner stays one block on phones (BuddyNext Pro).
- **Safer broadcasts.** Members awaiting approval and suspended members are skipped, and the check reads only the broadcast's audience (BuddyNext Pro).

## Better for members

- **Discussions tab.** A Space's Discussions tab shows Pinned, Solved and Closed and up to two tags on each topic.
- **Load more.** The Space and profile Media tabs, albums and album pictures page with Load more, so a large library is fully reachable.
- **A clearer composer.** The composer is one card with Post and Article tabs, the button reads Post or Write article, the audience shows just your choice, and on a phone the extra tools fold behind one More button. Members with no photo see their initials on a tinted disc.
- **Level and points privacy.** A member who turns off Show my level and points has the level removed from their badge cards, and their points are left off the leaderboard for other members. Administrators and moderators still see them. This needs WB Gamification 1.7.0.
- **Space leaderboard.** Your position shows only to the Space's members, and the points label follows your site's own name for points.
- **Notices open the right page.** With the Gamification navigation switched off, notices and badge cards open the member's profile. A badge card is withdrawn when its credential expires.
- **Calmer notices.** Notices show two at a time on a desktop and one on a phone, a moment apart, and none is dropped.
- **Open to Work** (BuddyNext Pro with Career Board). The card shows skills, place, experience and photo, follows the resume's access setting, and stays current when the photo changes.

## Faster on large sites

- Posting in a big Space delivers in fixed-size batches.
- Deleting a forum Space removes its topics' feed cards and search rows.
- The For you feed stays fast for members with many connections or interests.
- Background jobs that continue in chunks no longer queue the same next chunk twice, and the upgrade clears duplicates older versions left queued.

## Polish in this release

- **Undo.** Unfollow and Mute show an Undo button, and banning from a Space can record a reason.
- **Keyboard and dialogs.** The post menu, the Posting to popover and the emoji sheet work from the keyboard, dialogs hold focus, and warning confirms open on the safe choice.
- **Suspended members** are left out of the Space sidebar Members preview and the Members Active tab, and see one banner with the appeal link on every community page.
- **Admin.** Text areas resize, the Members list, Email Log and member search show avatars, a mistyped address in an empty From, Reply-To or alert email setting is refused, and Save is disabled until a field changes, tables fit their cards on phones, and Insights rows are one height.
- **Mail** has a dark theme, the main action is the brand button, and moderation notices carry no Unsubscribe link.
- **Space pill.** A Space page shows an N new posts pill from its own count.

## Fixes

- A suspended member who has not finished onboarding reaches the account status page to read the reason and appeal.
- Nobody can suspend the site owner's account. Administrators can still suspend other administrators.
- A member banned from a Space before Discussions was switched on cannot post in the new forum.
- A guest no longer learns of private pictures over REST, and a suspended member's Media tab shows nothing.
- The albums grid on a phone no longer scrolls sideways when a title is long, and album titles show apostrophes and dashes as typed.
- Outlined buttons, form fields, chips, switches and muted text meet WCAG contrast in light and dark mode.
- Emails are built in the recipient's language, and the default email templates are translated in all ten shipped languages.
- Report queues say View reported member, post, comment or space, and a Space ban reason is kept in the moderation log.
- The owner's account cannot be given a strike, and suspended or pending members cannot delete articles or upload through Member Blog.
- The member export and the privacy export show dates in the site timezone, and the privacy export includes every invitation.
- Pending approval shows the account type the member chose, account emails carry clickable links, and the cookie notice and sign-up quote keep their line breaks.
- Spaces search finds a Space by its Location.

## Developers

- `buddynext_card_live`, `buddynext_card_facts` and `buddynext_card_actions` let a bridge supply live card values. See [Extending Cookbook](../developer-guide/41-extending-cookbook.md).
- `buddynext_safeguard_check` passes what is being written as a sixth argument.
- `buddynext_notification_url` receives the recipient as a sixth argument, and `buddynext_card_facts` also runs on the REST card with the card's `user_id`.
- `IntegrationActivity::actions()` takes an optional `$title_url`, and `GamificationBridge::viewer_rank()` and `can_view_points()` are new. See [Gamification seam](../developer-guide/45-gamification-seam.md).
- `window.bnToast` takes `icon`, `count`, `key` and the `award` type, returns a handle, and reports `bnToast.supports`. See [Toasts and dialogs](../developer-guide/56-toasts-and-dialogs.md).
- Integration registry entries take `floor_disables` beside `min_version`.
- `IntegrationActivity::refresh_cards_by_meta()` takes `$opts` (`user_id`, `published_only`, `link_url` with `link_arg`, `batch` with `after_id`) and a `$next` cursor.
- Profile panels are cached for the owner, other members and visitors separately, and `SuitePanelHost::flush()` clears all three.
- `buddynextpro_segment_excluded_user_ids` receives only ids inside the audience.

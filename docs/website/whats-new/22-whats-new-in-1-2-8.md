# What's New in 1.2.8

This release lets owners lay out the profile form, folds tab bars that run out of room into a **More** menu, makes every profile and space tab manageable in **Settings > Navigation**, and fixes what two QA rounds found across spaces, sign-in, media and the admin. BuddyNext Pro 1.2.8 matches it with clearer paywalls, readable analytics charts and broadcasts that respect the site timezone.

> **Note:** BuddyNext free and BuddyNext Pro are released together. Update both at the same time and test them together.

## New for site owners

- **Profile field width.** Each profile field has a **Width**: Auto, Half row or Full row. Two Half fields share a row, so ZIP and City can sit side by side under a full-width Street. Auto keeps the layout you had. See [Custom Profile Fields](../members/02-profile-fields.md).
- **Group intro.** A field group can carry a short intro, shown above its fields on the profile edit and sign-up forms, such as "These details are only visible to staff." It is not shown on the member's profile.
- **Tab bars fold into More.** Profile, space, settings and feed filter tabs show every tab that fits. Only the tabs that do not fit go into a **More** menu, and the tab you are on always stays in view. A wide screen that fits every tab shows no More.
- **Every tab is in Settings > Navigation.** Profile Tabs and Space Tabs list every tab members can see, including tabs added by integrations such as Portfolio, Discussions and Businesses, in the order members see them. Each row says where the tab comes from, and a tab switched off in **Integration Settings** says so.
- **Stories on the home feed.** The stories bar sits at the top of the home feed, and the composer can **Also add to my story**.
- **Space categories by level.** A category can be limited to main spaces, subspaces or both. The create and settings forms only offer categories for the right level.
- **Lock a member type once chosen.** A member type can be set so members pick it once; they are warned before they pick.
- **Sign in with your @handle** on every BuddyNext login form.

## Better for site owners

- **Navigation and admin.**
  - On phones the BuddyNext admin menu folds behind one "Section › Tab" button, and the menu stays open on every hub section.
  - A feature with its own settings screen links to it from **Platform > Features**, and the link opens inside the BuddyNext admin.
  - Old standalone addresses for Realtime, Push, AI Feed, Payments and White-label open inside the BuddyNext admin.
  - The setup reminder can be hidden and no longer shows on other plugins' screens.
  - A greyed-out setting keeps its value when its screen is saved.
- **Bulk actions** ask before deleting and name how many items they affect; Spaces and Members report what really happened, including failures.
- **Integration Settings** name the next step for each companion: Activate, Install, or Needs BuddyNext Pro. A failed companion install shows its error on the card.
- **Setup wizard.** Save & exit saves the current step, and leaving the brand colour untouched lets your theme's accent stay in charge.
- **Brand colour** flows into emails, the installed app's theme colour and membership receipts. With no colour picked, everything uses the accent your site already shows.
- **Email editor.** **Send test** sends what is in the editor, even unsaved, and names the address it went to.
- **Email verification.** An account an administrator creates is verified and never cleaned up. The unverified-account cleanup only touches accounts made on BuddyNext's own sign-up form.
- **Member approval.** The approval queue stays reachable while anyone is waiting, and Approve acts once.
- **Spaces.**
  - Space email invites give one neutral answer and are rate limited below site level.
  - The space directory search works together with category, sort and sub-spaces.
  - Space moderation lists page past 20.
  - Owners reach the space Admin page from the space header, and moderators can leave a space.
  - Sub-spaces cannot be featured.
  - Partner tabs such as Events and Businesses are on in every space by default, and settings for integrations you switched off are hidden.
- **Signed updates.** Before an update of BuddyNext or BuddyNext Pro installs, the package is checked for Wbcom's release signature. The result is logged and the update installs either way.
- **Page caching.** The first page after signing in, signing up or a two-factor step is never served from a page cache. The caching guide explains the W3 Total Cache setting that must stay on.
- **Translations** are complete for 10 languages.

## Better for members

- **Profile.**
  - Your own profile shows **Share profile** and **Share to feed** in the action row while they fit.
  - A locked profile says who can see it and offers the Follow, Connect or upgrade that opens it.
  - Member cards show **Requested** after a request.
  - The headline and bio hide what a member chose to hide.
- **Posts.**
  - Editing a post keeps its paragraphs, mentions and hashtags.
  - @mention and #hashtag suggestions appear when editing a post and when replying to a comment.
  - Photo, file and link posts without a caption can be edited, and gain a caption box.
  - A poll's end time starts at now and cannot be set in the past.
- **Comments.**
  - Replies on the deepest level join that level and stay readable on phones.
  - A pinned comment takes one slot on the first page only.
  - A comment you reported shows **Reported** after a reload.
- **Media.**
  - The viewer's Delete and a tile's Remove both move the item to the trash.
  - Changing **Allow downloads** applies without a page reload.
  - A no-download player has no right-click menu.
- **Leaving a space** asks first, and says why when it cannot be done. The same applies to a join request.
- **Messages.** Old photos sent as attachments show as photos, and a refused **Leave group** keeps you in the conversation and says why.
- **Notifications.** Turning **In-app** off only clears the bell; email, push and digests keep working. Hiding an integration's tab no longer silences its notifications.
- **Refusals** show the server's real reason, and a refusal that will not change stops saying "Try again".
- **Unblock, unmute and unrestrict** confirm the result.
- **Accessibility.** The photo viewer keeps keyboard focus, online status reads without colour, search announces how many results came back, and admin controls meet the 40px tap size on touch.

## Fixes

- With Rank Math or Yoast active, a shared profile or post shows its own link, title and picture, not the hub or home page. A public profile shares its cover photo.
- Every profile and space tab can be hidden, renamed and reordered, including Portfolio, About and Network.
- Profile header spacing is even, and a pending connection request keeps the action buttons in place.
- Sidebar **Follow** buttons are lighter and leave room for the member's name.
- Ending an announcement removes that announcement's card, not the first one on the page.
- Pinning a comment clears the replaced comment's badge.
- Directory labels and member-type badges stay on the card after a search, filter or page change.
- Discussions empty states offer each viewer their real next step, and a failed forum link says why with a fresh retry.
- Jetonomy discussion links with emoji or accented titles open again, and a BuddyNext suspension makes the member read-only in the forum.
- The stories bar's script loads, so members can add and open stories.
- The Insights new members chart shows its counts and dates.
- A post the viewer cannot read answers 404 on block themes.
- A password reset that hits the rate limit stays on the form with an error, the emailed login code checks the answer, and a refused member with the right password hears the real reason.

## For developers

- **Filter.** `buddynext_max_media_per_post` sets how many files a post may carry (default 4), read by the server, the composer and the edit form.
- **Browser event.** `buddynext:post-counts` lets other scripts push new reaction, comment and share totals into post cards.
- **Profile fields.** Fields carry `width` (`auto`, `half`, `full`) and groups carry `intro`, on the profile-field REST routes, in the member profile response and in `buddynext_register_profile_field()`.
- **Navigation API.** A registered profile or space tab is listed in **Settings > Navigation** automatically; `integration` and `description` label its row. `AdminHub::legacy_page_url()` returns the hub tab an old `?page=` slug was declared for.
- **Write APIs** for joins, bookmarks, shares and last activity take an optional source time, so an importer can keep original dates.
- **Search.** `buddynext_search_performed` fires once per search.
- **Schema.** An update applies on the first request of any kind, one request at a time, with data migrations queued.

## BuddyNext Pro 1.2.8

- **Paywalls.** A buy button only sells a plan that opens that content, never an unlisted plan the owner did not attach, and it is hidden when nothing can be bought.
- **Membership.** A failed cancel, checkout, payment page or plan change says the real reason and keeps the coupon you typed.
- **Broadcasts** show the send time in the site timezone, refuse a past time, and can be edited and rescheduled from wp-admin. Broadcast and drip email previews are sandboxed.
- **Analytics.** Line charts show dates, hover values and a data table; bar charts name every bar and its value; cohorts count registrations again.
- **AI reply** suggestions show as chips you can pick.
- **Realtime** reaction and comment counts go down as well as up.
- **Report-threshold rules** count only reporters past a minimum account age, shared with BuddyNext's own reporting rule.
- **License** tab shows a lapsed key's status, date and a renew link.
- **Space Businesses** shows members where their listings stand, with a link to add one, and approved WB Listora reviews appear in the community feed.
- Old Realtime, AI Feed, Payments and White-label addresses open inside the BuddyNext admin.

# What's New in 1.0.9

BuddyNext 1.0.9 is about consistency at every surface. A profile section that only knew how to draw a handful of fields now draws any field group by its type. Sidebars that showed the same column everywhere are now curated per page. Uploads that appeared in one place but not another now all land on the feed. Integration activity that each partner rendered its own way now shares one card. The theme is that the product should look and behave the same whether you reach a feature from the composer, an album, the Media tab, or a connected app.

> **Note:** BuddyNext free and BuddyNext Pro are released together. If you run both, update them at the same time so they stay in step.

## An About tab that renders any field

The profile About tab used to know how to draw only the fields BuddyNext shipped with. Add a custom group, or a field type it did not anticipate, and the layout broke or the value fell back to plain text.

It now renders any field group by its type, so a group you build in **Settings > Profile Fields** lays out cleanly with no per-field code behind it. Each type is drawn the way a reader expects:

- A paragraph field keeps its line breaks instead of collapsing to one run of text.
- A multi-select shows each value as its own chip rather than a comma-joined string.
- Structured groups like Work and Education show their dates and any sub-fields an admin added.

The result is that a custom profile - the kind every community builds - reads as deliberately as the built-in one.

## Sidebar widgets curated for the page you are on

Every page used to show the same sidebar. A widget that made sense on the feed sat just as prominently on the members directory, where it did not belong.

Sidebar widgets are now organised per surface. Feed, Explore, Members, Spaces, Profile, Notifications, and Hashtags each get a column chosen for that page, so the aside supports what you came to the page to do instead of repeating a one-size-fits-all list.

## Every upload lands on your feed

Where a photo appeared depended on where you uploaded it. A photo from the composer showed on the feed; the same photo added through the Media tab or an album might not.

Now every member upload creates a feed post. A photo from the composer, the Media tab, or an album all appear on your feed the same way, so your activity is a complete record of what you shared rather than a partial one that depends on which button you happened to use.

## Upload progress you can see, everywhere

Some upload points showed a spinner while the file went up and some just sat there, which reads as a frozen screen on a slow connection.

Upload progress indicators were added across every upload point: the composer, DM attachments, avatar, cover image, the album picker, and onboarding. Wherever you hand BuddyNext a file, it now tells you the upload is running.

## One card for every integration

Connected apps each rendered their activity their own way, so a badge award, an event, and a course update looked like three unrelated things in the feed.

Integration activity now renders as a single, consistent, typed card. Events, badges, and other connected-app activity share one layout, so the feed reads as one stream rather than a patchwork of partner styles.

## Integration notifications, collected and quiet

Badge and level-up notifications from integrations are now collected for display in one place, so a member sees their achievements together rather than scattered. And BuddyNext never sends a duplicate email for them - the partner plugin owns its own email, and BuddyNext only aggregates the notification for display. You will not get two emails for one badge.

## Built for big communities

Member directories, the space roster, and the discovery asides are now cached per viewer. On a large community these lists were rebuilt on every page load; caching them per viewer means the pages that grow with your membership stay fast as the numbers climb, while each member still sees the list from their own vantage point.

## The fixes that matter most

- **Private content stays private in Replies and Likes.** The profile Replies and Likes tabs could surface a private post's content to anyone who opened them. They now respect the same privacy the post itself carries.
- **Album media has a home to link to.** A media item added to an album from the picker had no source post, so its single-media link bounced to the Media tab instead of opening the item. Album-picker media now carries a source post, and its link lands where it should.
- **No feed card left behind.** A feed card could linger after the media or event it pointed to was deleted. Deleting the source now clears its card.

## Smaller things members and owners will notice

- **A profile field can share a name with an existing one.** Creating a field failed if its name matched another field's; that restriction is gone. Renaming a field group from wp-admin also sticks now instead of silently reverting.
- **Space membership and forums surface correctly.** `GET /spaces/{id}` now returns the viewer's own membership block, and provisioning a space forum brings up its Discussions tab.
- **Cleaner asides and admin toolbars.** The People to Follow widget no longer overlaps names, and several wp-admin toolbars and row-action menus that sat misaligned are squared up.

## Under the hood

1.0.9 also lays the first stone for the native app and tightens the time contract. `GET /buddynext/v1/app/config` gives the mobile app a single bootstrap handshake for site identity, feature flags, and the time contract, so the app learns everything it needs about a site in one call. App-facing REST timestamps now carry a UTC ISO `*_gmt` sibling through one dispatch seam, filterable per namespace, and the members directory query is prepared in the model instead of the template.

The full, human-readable changelog for each plugin lives on its release-notes page: [BuddyNext release notes](https://wbcomdesigns.com/release-notes/buddynext/) and [BuddyNext Pro release notes](https://wbcomdesigns.com/release-notes/buddynext-pro/).

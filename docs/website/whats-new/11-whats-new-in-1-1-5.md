# What's New in 1.1.5

A space can be searched from inside it, discovery survives a phone screen, and space post emails are delivered again. Most of this release is fixes, and several of them were failing silently - the kind you do not report because nothing looks broken.

> **Note:** BuddyNext free and BuddyNext Pro are released together. If you run both, update them at the same time so they stay in step.

## Search inside a space

A space gets harder to search exactly as it succeeds. Past the first screenful, finding "that thread about pricing from last month" meant scrolling, or running a community-wide search and mentally discarding every other space's results. Pinned posts only ever covered the handful an owner pinned.

A space's **Feed** tab now has its own search box, and it returns only that space's posts.

- Matches render as the same post cards as the feed, so you can react, comment and open them normally.
- Results are paged, twenty at a time, with Previous and Next. Every match is reachable, not just the first twenty.
- The result page is an ordinary address you can bookmark or send to someone else who can see the space.
- **Clear** puts the feed back.

It follows the space's privacy rather than adding its own. You find exactly the posts you could already reach by scrolling - a private space's posts if you are an active member, nothing from it if you are not. In a space you cannot read, the box is not offered at all, and a hand-typed search address returns nothing rather than a count. A count would itself tell you something about what is in there.

## Discovery works on a phone

Trending topics, people to follow, spaces to discover, browse-by-category, who is online: below 1025px, all of it disappeared. Not only on the feed - Explore, Members, Spaces and hashtag pages put their discovery content in the same column, so on a phone the community had no way to discover anything at all.

That content now appears beneath the main content instead of vanishing. Desktop is unchanged.

One thing deliberately did not change: on your own profile you still see a single completion indicator, not two. The compact one in the profile header exists precisely because the fuller card was hidden on small screens, so it steps aside on desktop and the card steps aside on mobile.

## Space post emails are sent again

If your site runs background jobs - which is to say almost every site - the email telling members about a new post in their space was never delivered. The job failed every time.

Nothing looked wrong. The in-app notification arrived normally, so the bell was correct and only the email was missing; the failure existed in a background log nobody reads. If members told you they were not getting space emails, this was why.

## Your browser tab says which screen you are on

Notifications and Messages showed the site name twice. Other community screens showed the page's name rather than the screen's. Every community page now names the screen it is.

## Other fixes

- A member can verify their own email address from their profile, and an admin can confirm a member in one click from the member editor.
- The admin menu lists entry points instead of every screen, each tab carries an icon that tells it apart, and the menu highlights the section you are in. Every screen stays reachable.
- The hub Pages table in Settings scrolls on a tablet, so its last column can be reached.
- A missing database table is repaired on the next load. The installer used to treat a matching recorded version as proof the tables were there, so a table lost to a partial restore stayed missing.
- The offline service worker follows your site's real login, admin and REST paths instead of assuming the defaults.
- The member editor tells you when a profile failed to save, instead of reporting success.
- The verified badge no longer appears on sites that do not run email verification.
- The Spaces directory set to List renders compact rows instead of stretched cards.
- A visitor switching the members directory between grid and list no longer overwrites the layout you set on the block.

## For developers

- `scope_space_id` on the `buddynext_search_query_args` filter restricts content results to a single space. It narrows the existing visibility rules and never widens them.
- `PostService::get_many()` hydrates a batch of post IDs in one query, in the order asked for, skipping IDs with no row.
- A sidebar widget can declare `mobile => false` when it already has a purpose-built mobile surface, so the same information does not render twice on one screen.

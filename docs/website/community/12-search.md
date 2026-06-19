# Search

Search lets anyone find members, posts, spaces, and hashtags from one query. Type a word and BuddyNext returns grouped, viewer-aware results across the whole community.

## Why use it

A growing community quickly holds thousands of posts, hundreds of members, and dozens of spaces. Without search, people give up looking and the community feels smaller than it is. Good search is how a new member finds the people they came for, how a long-time member re-finds a discussion from last month, and how anyone discovers a space they did not know existed.

Search returns results grouped by type - members, posts, spaces, hashtags, and media - so a person scanning the page can jump straight to the kind of thing they want. Because results are viewer-aware, every member sees a result set that is correct for them: nothing from people they have blocked, and nothing from suspended or shadow-banned authors. That keeps search trustworthy, which is what keeps people using it.

For the site owner, search needs no day-to-day attention. Content is indexed automatically as members post, and the index keeps itself current.

## How it works (for members)

### Run a search

1. Open the search page, which lives under your community's activity area.
2. Type a query into the search box and submit. The page reloads with grouped results.
3. Results are split into sections: Members, Posts, Spaces, Hashtags, and Media.

You can also press the `/` key on any community page to jump straight to the search box.

### Filter results by type

Above the results, type tabs let you narrow to a single kind of result:

- All (the default - shows every section)
- Members
- Posts
- Spaces
- Hashtags
- Media

Selecting a tab shows only that section, which is faster when you already know what you are looking for.

### Sort and date controls

Results carry date and sort controls so you can order them by recency or relevance and limit them to a time window. Changing a control reloads the results with the new ordering.

### What you will and will not see

- Only public content appears in results. Private posts and content from spaces you cannot see are not shown.
- Content from members you have blocked is excluded.
- Content from suspended or shadow-banned authors is excluded for everyone except admins.

> **Note:** Search works without JavaScript. The query form and the type tabs submit as plain page loads, so results are reachable on any device or connection.

## Setting it up (for owners)

Search is on as soon as BuddyNext is active - there are no keys to enter and no provider to connect. Indexing runs automatically in the background as members create, edit, or delete content.

### The Search Bar block

You can place a search input anywhere on your site with the Search Bar block. Add it through the block editor, where it is named "Search Bar" in the BuddyNext block category. It renders a unified search input that opens the grouped results page. The block has one option:

| Setting | What it does | Default |
|---|---|---|
| Placeholder | The grey hint text shown inside the empty search field | Empty (uses the built-in default text) |

The block also supports the standard editor controls for background and text color, font size, padding, and margin, so it fits the design of whatever page you place it on.

### Public explore and search visibility

Search results only ever contain public content, so the search page is safe to expose to logged-out visitors. The related visibility control for guests is the public explore feed - see Explore for how to make discovery surfaces members-only.

### Reindexing

You do not normally need to reindex. The index is updated as content changes. A full rebuild runs automatically when needed (for example after activation), handled in the background so it never blocks a page load. On large communities the rebuild is queued and processed in batches rather than all at once.

> _Screenshot: the search results page with grouped Members / Posts / Spaces / Hashtags sections and the type tabs - captured in the image pass._

## Good to know

- **Full-text matching.** Search uses the database's full-text engine to match words in titles and content, with a simpler word-match fallback in environments where full-text is unavailable. Members do not need to do anything different; results just work.
- **Empty query.** Submitting an empty query does not error. The page shows an empty state prompting you to type something.
- **Secret spaces stay hidden.** Spaces marked secret are indexed as private, so they never surface in public results.
- **Newly posted content.** Indexing is near-immediate, but on a very busy site a brand-new post may take a moment to appear in search while the background job runs.
- **Discoverability.** The main left-hand navigation does not include a dedicated Search link by default; members reach search through the on-page search box, the `/` shortcut, the Search Bar block, or any search link your theme provides.

## Free vs Pro

Unified search across members, posts, spaces, hashtags, and media - with viewer-aware filtering and the Search Bar block - is included free.

Pro adds advanced member filters on the search page. When BuddyNext Pro is active, the results page shows an "Advanced member filters" card that lets members narrow people by:

- Membership tier
- Space they belong to
- Member label
- Joined after a chosen date
- Active within a chosen number of days

Pro also adds saved searches, so a member can save a filter combination and re-run it later. These filters apply when there is a query and do not change the free behavior - when Pro is inactive, the advanced card is hidden and core search keeps working. The space filter only ever offers spaces the viewer already belongs to, so the advanced filters stay privacy-aware.

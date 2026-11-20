# Jetonomy

Jetonomy is the companion plugin that gives your community proper discussion boards. Turn it on, and members get a Discussions link and profile tab, plus an optional discussion area inside each space, where they can start topics, reply in threads, and vote on the best answers - the slower, more considered conversation a fast-moving feed cannot hold.

![BuddyNext admin Platform Add-ons tab showing the Jetonomy companion](../images/admin-integrations.webp)

Your feed and spaces stay exactly as they are; Jetonomy simply adds discussion boards alongside them. BuddyNext ties the two together so forums feel like a natural part of the community rather than a bolt-on.

## Why use it

The activity feed is built for fast, in-the-moment posts. It is the wrong place for a question that needs a considered answer, a topic the community will return to over weeks, or a debate with many branching replies. Those belong in a forum: a titled topic, threaded replies, and a best answer that stays findable.

For a community owner, forums add the structured half of community conversation. A space can hold a quick feed for day-to-day chatter and a forum for the questions and knowledge that deserve to last. For members, a discussion thread keeps a long conversation organized in one place instead of scattering it across feed posts.

The real scenario it solves: a member asks "how do I do X," and the answer is useful to everyone. In a feed, that question scrolls away in a day. In a forum it becomes a titled discussion, gets threaded replies, can be voted on, and stays searchable. Forums complement the feed - the feed is the heartbeat, the forum is the knowledge base.

## How it works (for members)

Once Jetonomy is active, these become available to members inside BuddyNext:

### The Discussions area

A **Discussions** link in the BuddyNext left navigation rail opens the forum home, for members and guests alike. Logged-in members also get **My discussions** in the personal group with Profile and Bookmarks, which opens their own profile **Discussions** tab. Every member profile has that tab, listing the discussions the member has started, with a count badge and an **Open the forum** button. The forum itself (browse, search, leaderboard, new topic) is Jetonomy's own set of pages, which the space tab and feed cards link out to.

On the pages of a forum that belongs to a space, Jetonomy's breadcrumb starts with a **Back to** link to that space, shown only to people who can see the space.

### Per-space forum tab

A space gets a **Discussions** tab once its owner turns on **Discussion** under **Manage space > Integrations** (off until then). Until then the space owner sees a **Turn on Discussions** link above the space's About tab; nobody else sees it. The tab lists the space's recent discussions with the author, reply count and vote count, an **Open in Community** button that opens the full forum, and a **Start a discussion** button for members who may post. When the forum has no threads yet, the **Start a discussion** button in the empty state opens the forum's new-topic composer directly. The full member experience of a space forum - starting a topic, replying, voting - is covered in Space Forum.

### Discussions in the activity feed

When a member starts a new discussion in a public forum, it can also appear as a "started a discussion" card in the BuddyNext activity feed, so people following the feed see new discussions without having to visit the forum. This mirroring is controlled by an owner setting (below) and only ever surfaces published, public discussions from public forums - private spaces and private topics never leak into the feed. A card follows its discussion: if the discussion is unpublished, trashed or made private the card is withdrawn, and publishing it again brings back the same card with its reactions and comments.

### Two-way discussion sync

A discussion card in the feed and its matching topic in the forum stay in sync automatically. Comment on the feed card, and that comment appears as a reply in the forum topic. Reply in the forum, and that reply appears as a comment on the feed card. Editing or deleting a comment or reply on either side carries through to the other, so members never have to re-post the same reply twice or worry about the two places drifting apart.

### Reply notifications and mentions

When someone replies to a member's discussion, or marks a reply as the accepted answer, that member gets a notification in the BuddyNext bell. Mentioning another member by their @handle inside a discussion notifies them too. Jetonomy owns the wording, who may see each notification, and its emails; BuddyNext shows the notification in the bell and does not send a second email. Members can switch each type on or off in their notification settings under **Forums**. The @handle is the member's BuddyNext handle, and profile links inside a discussion open the member's BuddyNext profile.

Reading a forum notification in BuddyNext, or choosing **Mark all as read**, also marks it read in Jetonomy's own notification list. A logged-in member who opens Jetonomy's notifications page is sent to the BuddyNext notifications page instead.

The **Forum email** card in the member's notification settings holds the forum email choices: one switch per forum notification type, and **Pause forum email**, which stops every forum email including the reminder to confirm the account email. When the member turns off the **Email** channel in BuddyNext, the forums send them no email either.

## Setting it up (for owners)

### Install and enable the companion (1-click)

Jetonomy installs from inside BuddyNext - no manual upload or plugin search.

1. Go to **BuddyNext > Platform > Add-ons**.
2. Find the **Jetonomy** card. Its description reads "Forum-style threaded discussions and Q&A boards."
3. Select **Install free**. BuddyNext pulls the plugin from the Wbcom store and installs it. The card then shows **Connected**.

If Jetonomy is already installed but switched off, the card shows an **Activate** button. The status badge shows Connected, Installed, activate, or Not installed.

> **Note:** The 1-click install needs a site administrator with permission to install and activate plugins.


### Display settings

Once Jetonomy is active, it gets a card on the **Integration Settings** screen, alongside every other integration.

| Setting | What it does | Default |
|---|---|---|
| Navigation (Jetonomy row) | Shows the Discussions and My discussions links in the left rail and the Discussions tab on member profiles. Turning it off also hides the space Discussions tab. Jetonomy notifications still reach the bell. | On |
| Feed (Jetonomy row) | When on, a new discussion started in a public space appears as a card in the BuddyNext activity feed, linking back to the full thread. Only public discussions in public, published topics are surfaced; private spaces and private topics are never mirrored. | On |
| Search (Jetonomy row) | When on, discussions are indexed for BuddyNext's community search, so members find them alongside posts, members, and spaces. Switching it off also removes the discussions already in the search index. | On |

All three switches are on by default - when Jetonomy is active, new public discussions flow into the feed and into search automatically. The feed and search switches are independent: you can keep discussions searchable while keeping them out of the feed, or the reverse. A space owner can also keep a space's activity out of the main feed with **Share activity to the main feed** under **Manage space > Integrations**.

### Points for forum activity

If WB Gamification is active, a topic, a reply and a topic removed by a moderator also write points to the member's ledger (by default 2, 1 and a deduction). These values are Jetonomy's own reputation settings: change them in Jetonomy's settings, not on the Gamification Actions screen, which does not list them.

### Suspensions and blocks

A member suspended in BuddyNext keeps reading the forum but cannot post, reply or vote until the suspension ends. People a member has blocked in BuddyNext are hidden from that member's forum lists and notifications. This needs a recent Jetonomy (2.0.7 or later); an older Jetonomy ignores it. Muting and restricting do not carry over.

### Banned words in the forum

The banned words and blocked links you set for the community also apply to forum topics (title and body) and replies. A topic or reply that contains one is refused with one general message, and the member is not told which word or rule matched; that detail stays in the moderation log. Flag-only rules let the text through, as they do for comments. Editing a topic or reply is checked the same way, so a clean post cannot be edited into a banned one.

### Forum reports in the moderation queue

When a member reports a topic or reply in the forum, the report also appears in the BuddyNext moderation queue. Your community moderators can act on it there with Remove, Warn, Strike and Suspend, even if they hold no forum role: Remove moves the topic or reply to the forum trash, so it can be restored. Closing a report in either place closes it in the other. If the same discussion was also reported from its card in the activity feed, both reports close together. The queue names the forum a report came from only to moderators who can see that forum, and never shows a topic's title.

### Per-space forum

Each space owner decides whether their space has a discussion. Under **Manage space > Integrations**, the owner switches **Discussion** on; BuddyNext then creates one dedicated discussion for the space automatically. Instead of creating a new one, the owner can search for and link a discussion they already own (a site administrator can link any). A space keeps one discussion for its lifetime, and switching it off only hides the tab - nothing is deleted, and switching it on again restores the same discussion. Moderators can open the screen but only the owner can change this switch, link or unlink a discussion, through the screen or the app. A discussion belongs to one space: one already linked to another space is not offered in the search and cannot be linked again. If your site has a discussion linked to two spaces from before this rule, `wp buddynext repair-discussion-visibility --dry-run` lists it without changing anything; unlink it from the space that should not have it. When the owner has switched Discussion on and its forum is missing, the owner or a space moderator can start it again from the Discussions tab. See Space Forum for the member side.

## Good to know

- **Emoji and accented titles open correctly.** Links to discussions with emoji or accented characters in the title open from the feed, search, notifications and profile tab.
- **Forums never leak private content into the feed.** Only public, published discussions in public spaces become feed cards. A discussion in a private or secret space, or a topic marked private, stays out of the public feed and Explore even when feed sync is on.
- **Deleting a discussion cleans up after itself.** When a discussion is removed, its feed card and its search entry are removed too, so the feed never points at a thread that no longer exists.
- **Discussions are searchable.** New discussions are indexed for BuddyNext's unified search, so members find them alongside posts, members, and spaces. This is independent of feed sync - each has its own switch on the Jetonomy row. If you turn search indexing off, the discussions already indexed are removed too, so search does not keep answering with results you have just switched off.
- **The feed card and the forum topic never drift apart.** Every comment or reply, and every edit or delete of one, is mirrored to the other side automatically - there is nothing to re-post by hand.
- **Related discussions on hashtag pages.** A hashtag page lists up to five Jetonomy discussions that carry the same tag.
- **One leaderboard.** If WB Gamification hands its leaderboard to Jetonomy, BuddyNext hides its own Leaderboard link so members never see two competing rankings.
- **Inert when not installed.** With Jetonomy inactive, BuddyNext has no Discussions link, no space forum tab, and no feed sync - there are no errors or broken links. Installing the companion is what turns them on.

## Free vs Pro

The free Jetonomy companion delivers everything described above: the Discussions area, per-space forums, threaded replies, voting, mentions, reply notifications, feed sync, and the two-way sync between feed comments and forum replies.

Jetonomy's own paid plan extends the forum engine itself (for example its private-messaging extension). Inside a BuddyNext community, direct messaging is owned by BuddyNext through the MediaVerse companion, so when BuddyNext messaging is available it takes over the Messages area and Jetonomy's messaging extension steps aside - members get one consistent inbox rather than two. See MediaVerse and Direct Messaging for how messaging is provided.

## Related

- [Integrations Overview](01-overview.md) - how every companion plugin connects.
- [Space Forum](../spaces/09-space-forum.md) - the per-space forum this integration adds.
- [Activity Feed](../community/01-activity-feed.md) - where new discussions appear as cards.
- [MediaVerse](02-wpmediaverse.md) - the companion that owns direct messaging.

# Post Composer

The post composer is the box at the top of the feed where members write and publish posts. It handles plain text, photos and media, links with a preview card, polls, and scheduling, and it lets members edit, delete, pin, and save drafts of their own posts.

![The BuddyNext post composer at the top of the home activity feed, ready for a member to write and share a post](../images/community-activity-feed.png)

## Why use it

Posting has to be effortless or members will not do it. The composer is always visible at the top of the feed so writing a post is one click away, and it grows to fit whatever the member is sharing, a quick thought, a photo, a poll, or a link, without sending them to a separate page.

For members, the value is reach and control in one place: they write once, choose who sees it, and it lands in the right feeds. Drafts mean a half-written post is never lost, and the edit window lets them fix a typo without deleting and re-posting.

For owners, the composer is where you set the tone and the guardrails. Rate limits and review thresholds keep spam and drive-by accounts from flooding the feed, while link previews and the emoji picker keep posts rich and readable. You can tune all of it without touching code, so the same composer suits a tight professional network or a busy public community.

## How it works (for members)

### Writing and publishing a post

A member types into the composer and clicks Share. The post is created and appears in the feed. The composer supports several post types from one box:

- **Text** - the default. Just type and share. @mentions in the text notify the people named.
- **Photo and media** - attach images or media from the media tools. A member can only attach their own media.
- **Link** - paste a URL into the post. The community fetches the page's title, description, and thumbnail in the background and attaches a preview card. The preview may take a moment to appear after posting.
- **Poll** - turn the post into a poll with 2 to 5 options. Other members vote, with one vote per member per poll.
- **Schedule** - pick a future date and time. The post is held and published automatically when that time arrives; it stays out of the feed until then.


### Choosing who sees a post

Before sharing, a member picks an audience from the privacy menu in the composer: Public, Followers, Connections, or Only me. When posting inside a space, the audience is the space's members. The full meaning of each level and how it is enforced is covered in Post Privacy and Visibility.


### Editing and deleting your own posts

A member can edit their own post to fix or update the text and change its audience, as long as the edit window is still open (see the settings below). Edited content is re-scanned by the same content checks that run on a new post, so an edit cannot slip banned words or a blocked link past moderation. A member can delete their own post at any time.

### Pinning a post

A member can pin one of their own posts to the top of their profile so visitors see it first. Inside a space, a space organizer can pin a post to the top of that space. Free allows one pin per surface; Pro raises this (see Free vs Pro).

### Drafts

As a member writes, the composer saves the in-progress post as a draft so nothing is lost if they navigate away or close the tab. Drafts can also sync across devices so a post started on a phone can be finished on a laptop.

## Setting it up (for owners)

Every composer behavior below is controlled from the community settings. All work in Free.

| Setting | What it does | Default |
|---------|--------------|---------|
| Post edit window | How many minutes after posting a member can still edit their post. Set to 0 for no limit. Administrators are never limited. | 60 |
| Enable link previews | Whether pasted links get an auto-fetched preview card. Turn off to stop the community from fetching external pages. | On |
| Enable emoji picker | Whether the emoji picker is available in the composer. | On |
| Allow polls | Whether members can create poll posts. | On |
| Post rate limit (per minute) | Maximum posts one member may publish per minute, to stop flooding. Set to 0 to disable. Administrators and moderators are exempt. | 10 |
| Comment rate limit (per minute) | Maximum comments one member may publish per minute. | 30 |
| Duplicate post window | If a member re-posts identical text within this many minutes, the duplicate is published but flagged into the moderation queue for review. Set to 0 to disable. | 0 (off) |
| New member post threshold | New members whose total post count is below this number have their posts flagged into the moderation queue for review (the post still publishes). Set to 0 to disable. | 0 (off) |

> **Note:** BuddyNext uses reactive moderation, the same model as mainstream social platforms. The duplicate and new-member thresholds do not hold a post back; the post publishes and a report is filed so a moderator can review it after the fact. If you want posts held for approval before they appear, use pre-moderation in the Moderation settings instead.

> **Tip:** Leave the duplicate and new-member thresholds at 0 for an established, trusted community. Turn them on when you start seeing spam from fresh sign-ups; the new-member threshold is the most effective single setting against drive-by spam.

## Good to know

- **The schedule tool is in the Free composer.** A Free member can pick a future time and the post stays hidden until then, because the feed never shows future-dated posts. What Pro adds is a place to see and manage scheduled posts, not the clock itself.
- **Polls are 2 to 5 options.** Fewer than two or more than five is rejected with a clear message. Each member gets one vote per poll, and a vote cannot be changed once cast.
- **Announcements are admin-only.** A post typed as an announcement can only be created by an administrator, and it pins to the top of the feed as the announcement banner described in Activity Feed. Announcements can carry an optional expiry.
- **Suspended members cannot post.** A suspended account is blocked from creating posts, comments, and reactions until the suspension ends.
- **Archived spaces are read-only.** Once a space is archived it stops accepting new posts.

## Free vs Pro

The composer itself, including the schedule clock, link previews, emoji picker, polls, drafts, editing, deleting, and single-post pinning, is fully available in Free.

Pro adds two things on top:

- **Scheduled posts management** - a view to see, reschedule, and cancel posts you have queued for the future. Free can schedule a post; Pro is where you manage the queue.
- **Multi-pin** - pin more than one post to a profile or space at a time. Free allows a single pin per surface.

# Scheduled posts

Scheduled posts let a member write something now and have it publish automatically at a future date and time. The post stays out of every feed until its moment arrives, then goes live on its own. Pro turns the post composer's schedule clock into a real managed queue: the post is parked, tracked, and published on time, with a place to review, publish early, or cancel anything that is waiting.

![A scheduled post going live on time in the community activity feed](../images/community-activity-feed.png)

![Reviewing the queue of scheduled posts in the BuddyNext Pro settings](../images/backend-settings.png)

## Why use it

People rarely have time to write at the exact moment something should go out. A community manager prepares the week's announcements on Monday morning. A creator drafts three posts in one sitting but wants them spaced across the week so the feed does not get a burst followed by silence. Someone wants a welcome message to land first thing in the morning, not at midnight when they finished writing it. Scheduling solves all of these: write when you have the time, publish when it has impact.

For an owner, scheduling is what makes a community feel consistently active without anyone having to be online around the clock. Content can be planned ahead, queued, and trusted to appear on time, so the feed keeps a steady rhythm instead of going quiet for days and then flooding. It also gives the people who run your spaces a way to coordinate launches, events, and recurring updates the way they would on any mainstream platform.

In free BuddyNext, the composer already shows a "Schedule for later" clock and can hold a post until its time. Pro builds the management layer on top: a queue you can see and act on, owner-validated scheduling and cancelling, and an admin page that lists everything waiting across the whole community.

## How it works (for members)

### Schedule a post for later

In the post composer, open the schedule tool (the clock marked "Schedule for later") and pick a date and time. Write your post as normal and submit. Instead of going live, the post is parked with a scheduled status and is held back from every feed until the time you chose. You get a confirmation that the post has been scheduled.

The time you pick is the moment it publishes. Until then, nobody sees the post in the home feed, a space feed, or anywhere else, and the usual "new post" notifications do not fire. Those only go out when the post actually publishes, so followers and space members are notified at the right time, not when you queued it.


### When the post publishes

At the scheduled time, the post publishes on its own and behaves like any post made at that moment: it appears in the feed, counts toward the author, and triggers the normal new-post notifications. No further action is needed from the member.

### Review and cancel your scheduled posts

A member can see their own queued posts and cancel any that are still waiting. Cancelling a scheduled post does not delete it - it reverts the post to a draft and clears its scheduled time, so the content is preserved and can be rescheduled or edited later. Only the author of a scheduled post can cancel it.

## Setting it up (for owners)

### The scheduled posts queue

Pro adds a **Scheduled Posts** admin page (under the BuddyNext menu, in the Growth section) that lists every post waiting to publish across the community, ordered by the soonest scheduled time first. For each post you see its ID, author, type, an excerpt, and the scheduled time in UTC.


The queue gives owners three actions.

| Action | What it does |
| --- | --- |
| Publish Now | Publishes that single post immediately, ahead of its scheduled time. It goes live and triggers the normal new-post signals. |
| Cancel | Reverts that post to a draft and removes it from the queue. The content is kept, not deleted. |
| Publish Overdue Posts Now | Publishes every post whose scheduled time has already passed, in one click. Useful if you want to flush anything that is due right away rather than wait for the next automatic run. |

There are no settings to configure for scheduling - the queue and its actions are available as soon as Pro is active. The schedule clock in the composer is part of free BuddyNext and is on for members by default.

### How posts publish on time

The community checks for due posts automatically and publishes any whose scheduled time has arrived, then fires the normal new-post notifications for each one. This runs in the background on its own, so a correctly scheduled post goes live on time without anyone touching the admin page. The "Publish Overdue Posts Now" button is there for the moments you want to publish what is due immediately rather than wait for the next automatic check.

## Good to know

- A scheduled time must be in the future. If a member tries to schedule a post for a time that has already passed, the request is rejected and the post is not queued.
- Only the author of a scheduled post can cancel it. A member cannot cancel someone else's queued post. Owners and admins can still publish or cancel any post from the admin queue.
- Cancelling never destroys content. A cancelled scheduled post becomes a draft with its scheduled time cleared, so it can be rescheduled or edited.
- While a post is scheduled, it is hidden from every feed and its new-post notifications are suppressed. Both happen the moment it publishes, so members are not notified about a post that is not yet visible.
- All scheduled times are handled in UTC. The admin queue shows the scheduled time in UTC so there is no ambiguity about when a post will go out.
- If the queue is empty, the admin page shows a clear "No scheduled posts found" message rather than a blank table.

## Free vs Pro

The schedule clock in the post composer is part of free BuddyNext: a member can set a future publish time and free BuddyNext will hold the post and publish it when its time arrives.

Pro adds the management layer around that clock:

- Owner-validated scheduling and cancelling, with clear errors for a past date, a non-owner cancel, or a post that is not actually scheduled.
- A member-facing list of their own scheduled posts, with cancel.
- The admin **Scheduled Posts** queue listing every waiting post community-wide, with Publish Now, Cancel, and Publish Overdue Posts Now.

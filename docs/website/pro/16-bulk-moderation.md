# Bulk Moderation

Bulk Moderation is a Pro admin page that lets you act on many moderation items at once: select multiple reports and dismiss or remove them together, or enter a list of user IDs and warn or suspend all of them in a single submit. Every action reports back how many succeeded and how many failed.

## Why use it

Moderating a busy community one report at a time does not scale. A spam wave can drop fifty near-identical reports into your queue in minutes, and clicking through each one individually is slow and demoralizing. Bulk Moderation lets a moderator clear the obvious cases in a couple of clicks - select the spam reports, remove them all, done - and spend their attention on the cases that actually need judgment.

The same applies to people, not just posts. When you need to warn a group of accounts that crossed a line, or suspend a set of accounts caught in coordinated abuse, you paste their IDs and act on all of them at once instead of opening each profile in turn. At community scale, the time this saves is the difference between staying on top of moderation and falling behind it.

## How it works (for moderators)

The page has two sections: a queue of pending reports at the top, and a bulk user-actions panel below it.

### Bulk action on reports

1. Open BuddyNext > Moderation > Bulk (or the Bulk Moderation page under the BuddyNext menu). The pending report queue loads with a checkbox on each row.
2. Tick the reports you want to act on, or use the select-all checkbox in the header to select every report on the current page.
3. Choose an action - Dismiss or Remove - and optionally type a reason.
4. Apply. The page reloads with a summary of what happened.

Dismiss closes the reports as no action needed. Remove takes down the reported content. Both run through the same underlying moderation actions the single-item queue uses, so the result is identical to handling each report by hand - just faster.

### Bulk action on users

1. Scroll to the Bulk User Actions panel on the same page.
2. Enter a comma-separated list of user IDs in the User IDs field.
3. Type a reason.
4. For a suspension, set the duration in days.
5. Choose Warn Users or Suspend Users.

Warn sends each user a warning. Suspend suspends each user for the duration you set. As with reports, these call the same warn and suspend actions used for single members elsewhere in moderation.

### The success and failure summary

After any bulk action, the page returns a notice that tells you exactly how many items went through and how many did not - for example, "Dismissed: 12 succeeded, 0 failed." Each item is handled on its own, so one bad entry does not stop the rest. If you paste a user ID that does not exist, or a report that was already handled by another moderator, that one item is counted as failed with a reason while every valid item still completes. You always know precisely what the action did.

## Setting it up (for owners)

There is nothing to configure to use Bulk Moderation. The page is available to administrators as soon as BuddyNext Pro is active. Access is restricted to users who can manage the site, and every action is protected against cross-site request forgery, so only your trusted moderators can run bulk actions.

| Setting | What it does | Default |
|---|---|---|
| Reports per page | How many pending reports the queue shows per page before pagination kicks in. | 25 |
| Suspension duration (days) | Days a bulk suspension lasts, entered at action time. | 7 |

## Good to know

- The queue is paginated. The report queue shows 25 reports per page. Select-all selects the reports on the current page; page through to handle the rest. This keeps the page fast even when the queue is thousands of reports deep.
- It delegates to the same moderation actions. Bulk dismiss, remove, warn, and suspend all route through the same underlying actions that power single-item moderation. There is no separate "bulk" code path that could behave differently - a bulk remove is just many individual removes, each fully wired, with one combined result.
- It is concurrency-safe. Because each item is processed independently and reported on its own, a report another moderator already resolved, or a member already actioned, simply lands in the failed list with a reason while the rest of your selection completes.

> _Screenshot: the Bulk Moderation page showing the pending report queue with checkboxes and the select-all header, plus the Bulk User Actions panel below - captured in the image pass._

> _Screenshot: the post-action summary notice reading "succeeded / failed" after a bulk dismiss - captured in the image pass._

## Free vs Pro

Bulk Moderation is a Pro feature. The free plugin includes the report queue and single-item moderation actions (dismiss, remove, warn, suspend) - you act on one item at a time. Pro adds the bulk page that applies those same actions to many reports or many users in one submit. For AI-assisted scoring of content and the automated review of the report queue, see AI Feed and Moderation. For keyword, link, and rate-limit rules that act automatically, see Auto-Moderation.

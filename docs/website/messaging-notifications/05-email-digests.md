# Email Digests

A digest is a single email that batches up a member's unread notifications and sends them on a schedule - once a day or once a week - instead of one email per event. Members who would otherwise get a steady stream of individual emails can switch any notification type to a daily or weekly digest and get a single roundup instead.

![BuddyNext admin settings for configuring site-wide daily and weekly email digests](../images/backend-settings.png)

## Why use it

A busy community generates a lot of activity: follows, comments, reactions, mentions, space invites. If every one of those sends an immediate email, an active member's inbox fills up fast, and the usual reaction is to mute everything or unsubscribe. Once a member turns email off entirely, you have lost the channel that brings them back.

Digests fix that. They let a member stay subscribed while cutting the volume from many emails a day down to one. The member still hears about everything that happened, but on their terms, in one place. For the owner, that means fewer unsubscribes, fewer spam complaints, and a re-engagement email that members actually open because it is not noise.

Digests are also a gentle default for a large community: a weekly roundup keeps quieter members in the loop without ever feeling like the site is emailing them too much.

## How it works (for members)

Each member chooses how often they hear about each kind of notification from their notification preferences. For every notification type, the email frequency can be:

- **Immediate** - send an email as soon as it happens.
- **Daily** - hold it and include it in the next daily digest.
- **Weekly** - hold it and include it in the next weekly digest.
- **Off** - no email for this type (the in-app notification still appears).

When a member sets a type to Daily or Weekly, BuddyNext does not send that event email right away. Instead, the activity waits and is rolled into the member's next scheduled digest. The digest collects everything still unread from the period - the last 24 hours for a daily digest, the last 7 days for a weekly one - into one branded email with links back to each item. Anything a member has already read in-app is left out, so the digest only surfaces what they have not seen yet.

A member who reads everything as it happens in-app may receive an empty period and get no digest at all, which is intended - BuddyNext does not send a digest with nothing in it.


## Setting it up (for owners)

You control the site-wide digest behavior under Settings > Notifications. The two digest emails themselves (their subject, preview, and body) are editable under Settings > Email Templates, the same way as every other email - see Transactional Email System.

| Setting | What it does | Default |
|---|---|---|
| Digest frequency | Site-wide digest switch. `Disabled` turns off all digest emails. `Daily` and `Weekly` leave digests on, and each member's own per-type choice decides whether they get the daily or weekly digest. | Weekly |

> **Note:** The site-wide "Digest frequency" setting is a master switch, not a forced schedule. Setting it to Disabled stops every digest run for the whole site. When it is left on, the actual daily-or-weekly decision is made by each member in their own preferences.

The Daily Digest and Weekly Digest templates can be enabled, disabled, and rewritten in the Email Templates editor. If you disable a digest template, that digest stops sending even when the site-wide switch is on.

## How digests are generated

Digests are produced on a schedule by a background job, not when activity happens. BuddyNext runs a daily job and a weekly job. Each run:

- Finds the members whose preferences put them on that digest frequency.
- Collects each member's unread notifications from the period (24 hours for daily, 7 days for weekly).
- Sends one digest email per member through the same branded wrapper and sender identity as every other BuddyNext email.
- Records the send so the same digest is not sent twice in the same period, even if the job runs more than once.

Because the work runs in the background on a schedule, it does not slow down the site, and it scales to a large member base.

> **Tip:** For digests (and all scheduled email) to go out reliably, your site needs its scheduled tasks to run. On a low-traffic site, configure a real server cron so the background jobs fire on time even when no one is visiting.

## Good to know

- **Empty periods send nothing.** If a member has no unread notifications for the period, they receive no digest. This is intended.
- **Already-read items are skipped.** The digest only includes notifications the member has not already seen in-app.
- **Digests respect Off.** A type set to Off is never emailed at all - not immediately and not in a digest.
- **No double-sends.** Each member gets at most one daily digest per day and one weekly digest per week, regardless of how often the background job runs.

## Free vs Pro

Member-chosen daily and weekly digests, the site-wide digest frequency switch, and the scheduled generation are all included free.

Pro adds outbound email that goes beyond the per-member activity digest:

- **Broadcast campaigns** - send a one-off email to a chosen segment of members.
- **Drip sequences** - automated multi-step email series that send to members over time.
- **Space-level digests** - digests scoped to activity within a space, for members who follow that space.

All of these use the same branded wrapper and sender identity as the free emails. For the full picture of editable templates, sender identity, and the branded wrapper, see Transactional Email System.

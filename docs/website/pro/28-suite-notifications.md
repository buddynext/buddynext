# Suite Notification Aggregation (Pro)

When you run other Wbcom apps alongside BuddyNext, their notifications - a new job application from Career Board, a course update from Learnomy, an event reminder from Eventonomy - show up in the same place as everything else: your member's own `/notifications/` page. Nobody has to learn a second notification center for a second app.

> **Before you start:** This comes with BuddyNext Pro and needs at least one of the connected Wbcom apps active - Career Board, Learnomy, or Eventonomy today. With nothing else installed, there is nothing for it to mirror in.

## Why use it

Each Wbcom app already knows how to notify its own users about its own events. Left alone, that would mean a member juggling several apps has several places to check for news - one inbox for the community, another for their courses, another for their jobs. That is exactly the kind of fragmentation a unified community platform is supposed to remove.

Pro solves it by making BuddyNext the one notification center. Every connected app mirrors its own notifications into BuddyNext's feed the moment they happen, using the same bell, the same `/notifications/` page, and the same read/unread state a member already uses for follows, mentions, and space activity. The member checks one place.

## How it works (for members)

A member does not do anything differently. A notification from a connected app appears in their BuddyNext notification list exactly like any other - its own icon, its own short message, and a link that takes them straight to the relevant screen in that app.

- **Career Board** sends notifications under the "Jobs" label with a briefcase icon.
- **Learnomy** sends notifications under the "Courses" label with a graduation-cap icon.
- **Eventonomy** sends notifications under the "Events" label with a calendar icon.

Each one reads and clears the same way as a native BuddyNext notification.

### Choosing which apps notify you

On the notification preferences page, each connected app gets its own toggle, grouped alongside the BuddyNext notification types it is closest to (for example, Jobs sits with the other growth-related notifications). Turning an app's toggle off stops its notifications from reaching the member's BuddyNext inbox; the app itself is unaffected; a member who wants Career Board updates but not Learnomy updates can have exactly that.

> **Note:** These are on-site notifications only. BuddyNext never sends email on a connected app's behalf - each app remains responsible for its own emails, so a member is never emailed twice about the same event by two different plugins.

## Setting it up (for owners)

There is nothing to configure for this specifically. A connected app registers itself as a notification source the moment it is active alongside BuddyNext Pro, and its notifications start mirroring in immediately. There is no separate settings screen - the only owner-facing control is the per-app toggle on each member's own preferences, which members manage themselves.

## Good to know

- **Data-driven, not hardcoded.** Every connected app supplies its own ready-made message and link; BuddyNext renders it the same way it renders any other notification type. Adding a new connected app in a future release does not require any change to how notifications display.
- **No duplicate email.** A mirrored notification is on-site only. The source app owns its own email notifications for the same event, so nothing is sent twice.
- **On by default, per app.** When an app is newly connected, its notifications reach members immediately; a member opts out of one app's notifications rather than opting in.
- **Deep links go to the source app.** Selecting a mirrored notification takes the member to the relevant screen in Career Board, Learnomy, or Eventonomy - BuddyNext does not try to reproduce that app's content in the notification itself.

## Free vs Pro

Suite notification aggregation is part of BuddyNext Pro. Free's notification center - the bell, the `/notifications/` page, and the per-type preferences - is what every mirrored notification appears through, but Pro is what wires a connected app's own events into that center in the first place.

## Related

- [Suite Portfolio](24-suite-portfolio.md) - the profile-tab side of the same connected apps.
- [Notifications](../messaging-notifications/02-notifications.md) - the free notification center these appear in.
- [Notification Preferences](../messaging-notifications/03-notification-preferences.md) - where members manage the per-app toggle.

# Suite Notification Aggregation (Pro)

When you run other Wbcom apps alongside BuddyNext, their notifications - a new job application from Career Board, a course update from Learnomy, an event reminder from Eventonomy, a reply in Jetonomy, a badge from WB Gamification, a reaction in WPMediaVerse - show up in the same place as everything else: the member's bell and their own `/notifications/` page. Nobody has to learn a second notification center for a second app.

> **Before you start:** You need at least one connected Wbcom app active and on a version that sends its notifications to BuddyNext. The apps that do today are Jetonomy (Forums), Career Board (Jobs), Learnomy (Courses), Eventonomy (Events), WB Gamification (Achievements) and WPMediaVerse (Media). With none of them installed, there is nothing to show.

## Why use it

Each Wbcom app already knows how to notify its own users about its own events. Left alone, that would mean a member juggling several apps has several places to check for news - one inbox for the community, another for their courses, another for their jobs. That is exactly the kind of fragmentation a unified community platform is supposed to remove.

BuddyNext solves it by being the one notification center. Each connected app passes its notification to BuddyNext through one shared contract the moment it happens, and the app writes its own words and link. BuddyNext shows it in the bell with the same `/notifications/` page and the same read/unread state a member already uses for follows, mentions, and space activity. The member checks one place.

## How it works (for members)

A member does not do anything differently. A notification from a connected app appears in their BuddyNext notification list exactly like any other - its own icon, its own short message, and a link that takes them straight to the relevant screen in that app.

| App | Label in the bell | Icon |
|---|---|---|
| Jetonomy | Forums | Messages |
| Career Board | Jobs | Briefcase |
| Learnomy | Courses | Graduation cap |
| Eventonomy | Events | Calendar |
| WB Gamification | Achievements | Award |
| WPMediaVerse | Media | Image |

Each one reads and clears the same way as a native BuddyNext notification.

### Choosing which apps notify you

On the notification preferences page, each connected app gets its own section, named for its label above, with one on-site switch for each kind of notification the app sends (for example a reply, a badge, or an RSVP reminder). Turning a switch off stops that kind of notification from reaching the member's BuddyNext inbox; the app itself is unaffected. A member who wants Career Board updates but not Learnomy updates can have exactly that. Reactions from a connected app (for example WPMediaVerse media reactions) are bell-only.

> **Note:** These are bell notifications. BuddyNext sends an email for one only if the app asks for it for that kind of notification, and then the member can turn the email off in their preferences. Otherwise the app keeps sending its own emails, so a member is never emailed twice about the same event. While BuddyNext is active, WPMediaVerse sends no activity emails of its own (account-deletion confirmations are still sent).

## Setting it up (for owners)

There is nothing to configure for this specifically. A connected app that sends notifications to BuddyNext shows up as a source the moment it is active, and its notifications start appearing immediately. There is no separate settings screen for it. The owner control is the integration's **Show in navigation** switch on **Integration Settings**: while it is off, nothing from that app reaches the bell. Members manage the per-kind switches on their own preferences.

## Good to know

- **The app owns the wording.** Every connected app supplies its own ready-made message and link; BuddyNext displays them and does not rewrite them. A developer can add another app with the `buddynext_notification_sources` filter.
- **Older app versions.** For Learnomy and Eventonomy, BuddyNext Pro keeps an older path that copies their notifications into the bell. It steps aside automatically once the app sends through the contract, so a member never gets two rows for one event.
- **Hidden content stays hidden.** BuddyNext asks the app whether the member may still see what a notification is about, and removes the row when the app deletes the job, course, event or media item.
- **No duplicate email.** One email at most per event: either the app's own or BuddyNext's, never both.
- **On by default.** When an app is newly connected, its notifications reach members immediately unless the app declares a kind as off by default; a member opts out rather than opting in.
- **Blocked members.** A notification caused by someone the member has blocked, or who has blocked them, is not shown.
- **Deep links go to the source app.** Selecting a mirrored notification takes the member to the relevant screen in Career Board, Learnomy, or Eventonomy - BuddyNext does not try to reproduce that app's content in the notification itself.

## Free vs Pro

The receiver for these notifications is part of BuddyNext itself: the bell, the `/notifications/` page, the per-type preferences and the shared contract all live in the free plugin. BuddyNext Pro adds the older copy path for Learnomy and Eventonomy described above, and the bridges that put Career Board, Learnomy, Listora and Eventonomy activity into the feed and profile. WB Listora does not send notifications to BuddyNext yet.

## Related

- [Suite Portfolio](24-suite-portfolio.md) - the profile-tab side of the same connected apps.
- [Notifications](../messaging-notifications/02-notifications.md) - the free notification center these appear in.
- [Notification Preferences](../messaging-notifications/03-notification-preferences.md) - where members manage the per-app toggle.

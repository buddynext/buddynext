# Online Presence

BuddyNext shows who is around right now. When a member is active, an online indicator appears next to them across the community - in the member directory, on member cards, in profiles, and in the messaging rail. Members who were here a little earlier show as recently active. Presence updates on its own as people browse, so the community always looks current.

## Why it matters

An empty-looking community feels dead even when people are there. Online presence is what makes a space feel alive: a new member who lands on the directory and sees several people online right now knows this is a place where conversations happen, not an abandoned forum. For members, presence answers a simple, useful question before they reach out - is this person around to reply, or should I expect a slower response? An owner who wants their community to feel active and responsive gets that signal for free, with no configuration and no extra load on the site.

## How it works (for members)

You do not do anything to appear online. As you browse the community while logged in, BuddyNext quietly records that you are active. That activity is what powers your online indicator for everyone else, and the same thing happens for the people you see.

Where presence shows up:

- **Member directory.** Each member card shows an online indicator when that person is currently active. The directory also has an **Online only** switch that lists only members active in the last few minutes, and a **Recently active** sort so the people around right now rise to the top. The sidebar shows an **Online now** list of members too.
- **Member profiles.** A member's profile reflects whether they are currently active.
- **Member cards anywhere they appear**, including sidebar widgets such as an online-members list.
- **The messaging rail**, so you can see at a glance whether the person you are chatting with is around.

A member counts as online when they have been active within the last 10 minutes. Past that window they are no longer shown as online; they simply drop back to a regular listing rather than being flagged as active right now. A developer can change the window with the `buddynext_presence_online_window` filter (see the [hooks reference](../developer-guide/28-hooks-members-profiles-social.md)).

### How presence stays current

BuddyNext records that you are active at three moments, and only at those moments:

- **When a page loads.** Every full page view in the community while you are logged in refreshes your "last active" time.
- **When you move around without a reload.** BuddyNext swaps pages in the browser as you navigate, and each move refreshes it.
- **When you come back to a tab.** Return to a tab you left in the background and it refreshes again.

There is no background polling and no WordPress Heartbeat. A tab that is hidden or idle sends nothing. A tab sends at most one presence request every four minutes, and the server writes at most one update per member per minute, so a busy browsing session does not slow the site down. A member who reads one long thread stays online through the 10-minute window.

Because the browser sends the update itself, presence also works on sites that use a page cache: a cached page skips the server-side refresh, but the browser's own request still reaches the site. The community app uses the same request, so presence stays accurate on the website and in an app.

## Setting it up (for owners)

Online presence works automatically. There is no admin setting to switch it on, no provider to connect, and no keys to enter - the moment members are browsing, presence is recorded and shown. The directory's **Online only** switch and **Recently active** sort are part of the member directory and need no separate configuration.

There is currently no per-member privacy control to hide your online status. Presence is shown for active members as described above. If you need presence gated behind a different rule for your community, that is a developer-level customization rather than a built-in setting.

## Good to know

- **Active means the last 10 minutes.** Anyone active inside that window shows as online; after it, they quietly stop showing as online.
- **Presence is passive.** Members do not toggle themselves online or off - it follows real activity.
- **No flicker for active members.** A refresh at least every four minutes sits well inside the 10-minute window, so someone who is genuinely around stays shown as online.
- **Visitors do not see who is online.** Logged-out visitors get no online dots, and the **Online only** switch is not shown to them. A shared `?online=1` link simply shows them the normal directory.
- **An empty online list is normal.** On a quiet site at a quiet hour, the **Online only** view can legitimately be empty - that means no one has been active in the last few minutes, not that something is broken. If members are posting but the online list stays empty, see the Site Health check below.
- **No admin toggle and no member opt-out.** Presence is on by default and does not have a built-in privacy switch.

### Site Health check

Open **Tools > Site Health**. If members posted in the last hour but nobody was recorded as online, BuddyNext lists "BuddyNext is not recording who is online". The usual cause is a page cache that serves saved pages to signed-in members, or a security rule that blocks the BuddyNext REST API. Ask your host to skip page caching for signed-in visitors and to allow requests to `/wp-json/buddynext/v1/`. Posts made only from a mobile app can also trigger it, because an app does not open site pages. See [Performance and Cache](../troubleshooting/04-performance-and-cache.md).

## Free vs Pro

Everything described here - the online indicators, the "active recently" window, the automatic refresh, and the **Online only** directory view - works in free BuddyNext with no background polling. Pro can upgrade the transport underneath to push real-time updates over a live connection, but it uses the same presence signal, so the indicators and behavior members see are the same. Presence never breaks or disappears without Pro; Pro only makes the updates feel more instant.

## Related

- [Member Directory](04-member-directory.md) - the Online only switch and Recently active sort
- [Direct Messaging](../messaging-notifications/01-direct-messaging.md) - the messaging rail that shows presence
- [Near-Real-Time Updates](../engagement/03-realtime-updates.md) - how presence stays current across the site

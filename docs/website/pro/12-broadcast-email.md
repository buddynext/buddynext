# Broadcast Email

Broadcast email is a Pro feature that lets you send a one-off email campaign to your members. You write a campaign once - name, subject, and body - pick which members should receive it, send yourself a test, then dispatch it to everyone in the segment. Members can unsubscribe from your broadcasts at any time and manage their email preferences.

## Why use it

In-app notifications only reach members who are already on your site. Broadcast email reaches them in their inbox, where you can announce a launch, share an update, welcome a wave of new joiners, or bring lapsed members back. It is the difference between hoping people notice something on the feed and putting it directly in front of them.

Common reasons owners send a broadcast:

- Announce a new space, feature, or event to the whole community.
- Welcome everyone who joined in the last week.
- Re-engage members who haven't been active recently.
- Send a targeted message to one space, or to members carrying a particular label.

Because you choose the segment, you can keep messages relevant instead of emailing the entire membership every time. That keeps your unsubscribe rate low and your sends welcome.

## How it works (for members)

From a member's side, a broadcast is an email they receive, plus the ability to stop receiving them.

### Unsubscribing from a broadcast

Every broadcast carries a one-click unsubscribe link unique to that member. Clicking it records their choice and stops future broadcasts from reaching them - no login or form required.

### Managing email preferences

Members can also set an all-broadcasts opt-out from their account, which turns off every future broadcast at once rather than unsubscribing campaign by campaign. When a member has opted out, the system skips them when a campaign is dispatched, so they are never emailed against their preference.

> **Note:** The unsubscribe link in a sent email is signed with a secure token tied to that member and campaign. The link only works with its token attached - that is what proves the request is genuinely from the recipient and lets the unsubscribe happen without a login.

## Setting it up (for owners)

Broadcasts live under the BuddyNext admin menu on the Broadcast Campaigns page. All campaign actions require administrator access (manage options).

> _Screenshot: the Broadcast Campaigns admin page showing the campaign list and the New Campaign form with name, subject, body, and segment fields - captured in the image pass._

### Creating a campaign

Open the Broadcast Campaigns page and fill in the New Campaign form:

1. **Campaign Name** - an internal name so you can find the campaign in your list. Members never see it.
2. **Email Subject** - the subject line members see in their inbox.
3. **Email Body** - the message itself. You can write HTML for headings, links, and formatting.
4. **Segment** - who should receive it (see the segment table below).
5. Save the campaign. It starts as a draft.

### Choosing a recipient segment

A segment decides which members a campaign goes to. Pick one of these:

| Segment | Who it reaches |
|---|---|
| All users | Every registered member. |
| By space | Members of a space you choose. |
| By tag | Members carrying a tag you choose. |
| By activity level | Members grouped by how active they have been. |
| By join date | Members who joined within a date range you set. |
| By member label | Members carrying a Pro member label you choose (for example, Verified or Staff). |

### Sending a test

Before dispatching, use Send Test on the campaign. It emails a copy to your own admin address, with the subject prefixed so you can tell it apart, so you can confirm the formatting and links look right before any member receives it.

### Dispatching

When the campaign is ready, use Send Now. This queues a recipient for every member in the segment and begins sending in batches in the background, so a large send does not block your admin screen or time out. The campaign moves to a sending state and then to sent as the batches complete.

### Viewing recipients

Each campaign has a recipients view that lists the members it was sent to and their delivery status (queued, sent, or unsubscribed), so you can confirm a send went through and see who opted out.

### Settings

| Setting | What it controls | Default |
|---|---|---|
| Campaign Name | Internal label for the campaign. | Empty (you set it per campaign) |
| Email Subject | Subject line members see. | Empty (you set it per campaign) |
| Email Body | The HTML message body. | Empty (you set it per campaign) |
| Segment | Which members receive the campaign. | All users |
| Member all-broadcasts opt-out | Set by each member; excludes them from all broadcasts. | Off (member receives broadcasts) |

## Good to know

- **Include the unsubscribe link.** The per-member unsubscribe link is what makes the unsubscribe work and is expected on every bulk email. Make sure your campaign body includes the unsubscribe placeholder so each member gets their own working link; a delivered system footer also carries it when the body does not.
- **Open and click tracking is not available yet.** The current scope delivers your campaign and tracks per-recipient delivery status (queued, sent, unsubscribed). It does not yet report email opens or link clicks. This is current scope, not a fault - plan your measurement around delivery and unsubscribes for now.
- **Dispatch is one-way per campaign.** Once a campaign is sending or sent, sending it again is refused so members are not emailed twice from the same campaign. Create a new campaign to send again.
- **Empty segments send nothing.** If a segment resolves to zero members (for example, a space with no members), no emails go out and the campaign completes cleanly.
- **Unsubscribing is idempotent.** Clicking an unsubscribe link more than once is safe - the member stays unsubscribed and nothing breaks on the repeat click.
- **Sending happens in the background.** Large sends are processed in batches by a recurring background task rather than all at once, which is what keeps big campaigns reliable.

## Free vs Pro

Broadcast email is a Pro feature. BuddyNext Free sends transactional and notification email tied to community activity (for example, a notification that someone followed you). The ability to compose a standalone campaign, target it to a segment, send a test, dispatch it in batches, and give members per-campaign and all-broadcasts unsubscribe controls is part of Pro.

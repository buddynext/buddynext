# Kudos: peer recognition

Kudos is a small, direct way for one member to recognize another - a short note of thanks or praise, sent from one profile to another. Every member can give kudos, every member can receive it, and every kudos a member has received is visible on their profile.

<!-- TODO screenshot: the Kudos tab on a profile, showing the give-kudos form and the received-kudos feed -->

Kudos is part of gamification, provided by the WB Gamification companion plugin. It shares the Achievements area of the profile with the Achievements and Points tabs described in Gamification: points, badges, and levels.

## Why use it

Points and badges reward what the system can measure - posting, reacting, joining a space. Kudos rewards what only another person can see: the teammate who helped without being asked, the answer that actually solved someone's problem, the newcomer who made someone feel welcome. It puts recognition in members' own hands instead of leaving it entirely to automated scoring.

For an owner, kudos is a low-effort way to surface the quiet contributors a leaderboard alone tends to miss - the person who never posts much but always shows up to help. It costs nothing to turn on (it just needs the WB Gamification companion), and because it is peer-given, it feels more personal to the receiver than a badge the system handed out.

## How it works (for members)

### Giving kudos

Open another member's profile and go to their **Kudos** tab. If you are signed in and are not looking at your own profile, you will see a **Give kudos to [name]** form: an optional short message (up to 255 characters) and a **Send kudos** button. Leave the message blank to send plain recognition, or add a line saying what it was for.

Kudos cannot be given to yourself, and the profile you are viewing has to belong to a real, existing member.

### Receiving kudos

Every kudos you receive appears in your own Kudos tab as a feed: the giver's name and avatar, their message if they left one, and how long ago it arrived. There is nothing to configure and nothing to approve - a kudos shows up the moment it is sent.

### Limits on giving kudos

To keep kudos meaningful rather than automatic, a few limits apply to the giver:

- **A daily sending limit.** Each member can give a limited number of kudos per day (5 by default, set by the owner in WB Gamification). Once you reach it, sending another kudos is refused until the next day with a message telling you the limit.
- **A per-receiver cooldown.** You cannot send kudos to the same member again within a short window after your last one to them (one hour by default). This stops a member from repeatedly kudos-ing the same person back to back; the message tells you to try again later.

Both limits protect the receiving side of the feature too: without them, a member could inflate a friend's kudos count (and the points that come with it) in a burst.

### Points

Sending and receiving kudos both award points through the same gamification engine as every other action - the receiver earns more than the giver, since the point of the feature is to reward the person being recognized. See Gamification: points, badges, and levels for the values and how they fit alongside every other scored action.

## Setting it up (for owners)

Kudos needs no BuddyNext setting of its own. It appears automatically once the WB Gamification companion is active, because it is part of the same Achievements area as points and badges. See Gamification: points, badges, and levels for installing the companion.

The numbers that shape kudos - the daily sending limit and the points each side earns - are set in WB Gamification, not in BuddyNext:

| Setting | What it does | Default |
|---|---|---|
| Daily kudos limit | The most kudos one member can give in a day. | 5 |
| Points to the receiver | Points awarded to the member who receives kudos. | 5 |
| Points to the giver | Points awarded to the member who sends kudos. | 2 |

> **Tip:** The per-receiver cooldown (one hour by default) is a developer-level setting rather than an admin screen field. If your community needs a different value, it can be adjusted through a filter in WB Gamification.

### Moderating kudos

Kudos is member-authored content, so it can be misused - a member being pestered with unwanted kudos, or two members trading kudos back and forth to inflate their points. WB Gamification's own admin screens let a moderator review the kudos log, filter it by giver, receiver, or date, and revoke a specific kudos. Revoking reverses the points it awarded on both sides and keeps a record of the action for the audit trail; it does not delete the underlying row.

## Good to know

- **Kudos is separate from reactions.** A reaction is a one-tap emoji on a post or comment; kudos is a deliberate, addressed note from one member to another, sent from a profile rather than from a piece of content.
- **A revoked kudos stays visible in the log but not to members.** Once a moderator revokes a kudos, it drops out of the receiver's Kudos feed and its points are reversed on both sides, but the record itself is kept (not deleted) so there is a history of what happened.
- **The Kudos tab is where a member with nothing else lands.** If a member has no badges and no points yet, opening their Achievements area goes straight to Kudos rather than to an empty Achievements sub-tab, since anyone - even a brand-new member - can already have kudos to give or receive.
- **Works over the API too.** Giving kudos is also available as a REST action (`POST /buddynext/v1/kudos`) for the mobile app, enforcing the same self-kudos, daily-limit, and cooldown rules as the web form.

## Free vs Pro

Kudos works the same whether or not BuddyNext Pro is active. Like the rest of gamification, it depends on the WB Gamification companion plugin, not on a Pro license.

## Related

- [Gamification: points, badges, and levels](01-gamification.md) - the points kudos awards, and the tabs it sits alongside
- [The community leaderboard](02-leaderboard.md) - where accumulated points (including kudos points) are ranked
- [WB Gamification](../integrations/04-gamification-addon.md) - the companion plugin that owns kudos, its limits, and moderation

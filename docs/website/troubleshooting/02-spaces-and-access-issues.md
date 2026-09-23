# Spaces and Access Issues

Problems members or owners run into joining a space, hitting a paywall, or using an invite link.

## A member can't join a space, or the Join button doesn't work

**Symptom:** Clicking Join does nothing, or the person is refused.

**Likely causes and fixes:**

- **They're banned from the space.** A space ban blocks every future join or join-request attempt until an owner or moderator unbans them. Check the space's Members tab > "Banned members" section. See [Space Bans](../spaces/06-space-bans.md).
- **It's a Secret space.** Secret spaces have no Join button - the only way in is an invite from an owner or moderator. Confirm the space's privacy type. See [Space Types and Privacy](../spaces/03-space-types-and-privacy.md).
- **It's a Private space, or an Open space with "Require approval" on.** The click filed a join request instead of joining instantly - it's now waiting in the owner's or moderator's pending-requests queue, not stuck or broken. See [Managing Space Members](../spaces/04-managing-members.md).
- **It's a gated space (Pro) and they don't hold a qualifying plan.** They should see a paywall explaining which plan(s) open the space, not a silent failure - see the next entry if the paywall itself looks wrong.

## The paywall on a gated space isn't showing, or shows a broken/blank prompt

**Symptom:** A member without the required plan sees the space open anyway, or sees an unhelpful "not configured" message instead of an upgrade prompt.

**Likely cause:** Either the space isn't actually linked to a plan yet, or no gateway price and no CTA URL have been set for the paywall.

**Fix:**
1. Confirm the space is actually gated: **Monetization > Paywall**, check the gated-spaces table, or check the plan's own "Spaces this plan unlocks" field.
2. If it's not gated, that's expected - an ungated space has no lock, and there is currently no visual badge marking a gated space in directories, so tell members which spaces are premium in your description or pricing copy.
3. If it is gated and the prompt looks broken, set a **CTA URL** and **Description** under **Monetization > Paywall** (global defaults, or a per-space override). Without a linked gateway price and without a CTA URL, the paywall intentionally shows a "not configured" notice rather than failing outright - filling in either one clears it.

See [Gated Spaces](../pro/02-gated-spaces.md).

## A member with the right plan still can't get into a gated space

**Symptom:** The member insists they're subscribed, but the space still shows the paywall.

**Likely causes and fixes:**

- **Check which plan they're actually on.** BuddyNext treats a member as being on their most recent *active* subscription. If their plan expired, was cancelled, or was revoked, they've fallen back to the default/free plan - check **Monetization > Subscriptions**, filter by that member or plan, and confirm status is Active.
- **Check the space is linked to that specific plan**, or that their plan carries the **Gated Space Access** (all-access) perk. A plan not listed against the space, and without the all-access perk, does not open it.
- **They're mid-way through changing plans.** A plan change moves them onto the new plan immediately in most cases; a few situations (a free trial, a lifetime grant, mismatched currencies) refuse the switch instead - see [Membership Plans](../pro/01-membership-plans.md).

## An invite link says "no longer valid" or won't let someone in

**Symptom:** A shared space invite link tells the recipient it isn't valid, or does nothing.

**Likely causes and fixes:**

- **It expired.** Links can be set to expire in 1, 7 (default), or 30 days, or never. Check the space's Invite Link tab for the current expiry, and click **Create invite link** again (or reset it) if it's expired.
- **It hit its use limit.** Links can cap at 1, 10, 100, or unlimited uses. The tab shows "Used X of Y" - if the cap is reached, reset the link to issue a fresh one.
- **It was reset.** Resetting a link turns the old one off immediately. Only the newest link works - if you shared an old copy of the link (from an old email, an old post), it will no longer work. Re-share the current one from the space's Invite Link tab.
- **The person is banned from the space.** An invite link never lets a banned person back in, regardless of expiry or use count.

See [Invite people with a link](../spaces/11-invite-with-a-link.md).

## Members are added to a space through invite links faster than expected, or the "Joined via invite link" tag is missing

**Symptom:** Confusion about who joined through the link versus a direct join or request.

**Explanation, not a bug:** Every member who joins through the space's shareable link is flagged "Joined via invite link" on the space's Members tab - visible only to the owner and moderators, and it disappears if the member later leaves. If you don't see the tag, confirm you're looking at the Members tab of the specific space the link belonged to, not a site-wide member list.

See [Invite people with a link](../spaces/11-invite-with-a-link.md).

## A featured space isn't showing up where I expected

**Symptom:** A space marked Featured doesn't appear in the directory sidebar, the mobile strip, or onboarding.

**Likely causes and fixes:**

- **It's Private or Secret and the viewer isn't a member.** Featuring a private or secret space never overrides its privacy - it only ever shows to people who could already see it.
- **The onboarding "Spaces" step only shows Open featured spaces**, since that step is a one-click join flow - a featured Private or Secret space intentionally does not appear there.
- **You've already featured 6 spaces.** BuddyNext caps featuring at 6; unfeature one first to add another.
- **The space was archived or deleted.** Archived or deleted spaces drop out of every featured list automatically.

See [Feature Spaces for New Members](../spaces/12-featured-spaces.md).

## Related

- [Space Types and Privacy](../spaces/03-space-types-and-privacy.md)
- [Managing Space Members](../spaces/04-managing-members.md)
- [Space Bans](../spaces/06-space-bans.md)
- [Gated Spaces](../pro/02-gated-spaces.md)
- [Invite people with a link](../spaces/11-invite-with-a-link.md)

# Spaces and Membership FAQ

Questions about how spaces work, how they are gated, and how membership plans and invite links fit together.

## What are spaces?

Spaces are BuddyNext's groups - a place members join to post, discuss, and share media around a shared topic or purpose. Every space has one of three privacy types: Open, Private, or Secret. See [Space Types and Privacy](../spaces/03-space-types-and-privacy.md).

## What is the difference between Open, Private, and Secret spaces?

- **Open** - listed in the directory, anyone can join instantly (or send a join request if the owner turns on "Require approval to join").
- **Private** - listed in the directory so people know it exists, but joining requires a request the owner or a moderator approves, and the feed stays gated until they are.
- **Secret** - not listed anywhere. The only way in is an invite from the owner or a moderator.

See [Space Types and Privacy](../spaces/03-space-types-and-privacy.md) for the full comparison.

## Can I let members into an Open space but still review who joins?

Yes. Turn on **Require approval to join** in the space's Permissions settings. This is offered on Open spaces only - it turns a one-click join into a reviewed request while keeping the space fully readable to everyone. Private spaces already review every join by type, and Secret spaces are invite-only, so the toggle is not shown on those two. See [Managing Space Members](../spaces/04-managing-members.md).

## Can one membership plan unlock more than one space?

Yes, in both directions. From a plan, you list the spaces it should unlock; from a space, you tick every plan that should open it. A plan can also carry the **Gated Space Access** perk, which is an all-access pass - a subscriber to that plan can join any gated space on the site, regardless of which specific plans each space normally requires. See [Gated Spaces](../pro/02-gated-spaces.md) and [Membership Plans](../pro/01-membership-plans.md).

## What does a member see when they hit a gated space they don't have access to?

A paywall in place of the space content: a heading explaining the space is available to members only, your description, and an upgrade button. If more than one plan opens the space, the prompt names the cheapest one first. A member who already holds a qualifying plan never sees the paywall. See [Gated Spaces](../pro/02-gated-spaces.md).

## Can I grant a member a plan without them paying through checkout?

Yes. An administrator (or an app acting on the owner's behalf) can put a member on any plan directly through a cap-gated REST action, recorded with the source **Manual**, exactly like a real subscription - it opens the same gated spaces and unlocks the same protected content. See "Granting access without a purchase" in [Membership Plans](../pro/01-membership-plans.md).

## How do shareable space invite links work?

Every space can have one shareable invite link, created and reset by the space's owner, moderators, or a site admin. You set how long it lasts (1, 7, or 30 days, or never) and how many people it lets in (1, 10, 100, or unlimited), and anyone who opens it can join directly - no approval step, even on a private space. There is only ever one active link per space; resetting it turns the old one off immediately. See [Invite people with a link](../spaces/11-invite-with-a-link.md).

## Does an invite link work for someone who isn't signed in yet, or already a member?

Yes to both. A signed-out visitor sees "Log in to join" or "Sign up to join" and lands back on the space to join once they finish; an already-signed-in visitor joins immediately; an existing member just opens the space as normal. A banned member is never let back in through the link, and an expired, reset, or limit-reached link tells the person to ask for a new one rather than showing them the space's contents.

## Can an invite link skip registration steps or a paywall?

No. A link is a shortcut into the space, not a way around your site's rules. If your community requires email verification or a welcome step at sign-up, the person still completes it before landing on the space. If the space requires a paid plan, the link does not waive it - they still need the plan.

## What are featured spaces?

Featured spaces are the handful (up to 6) of spaces a site owner chooses to highlight so new members start somewhere deliberate instead of a near-random list. They lead the Spaces directory sidebar on desktop, show as a strip at the top on mobile, lead the "Spaces" step of new-member onboarding, and get a gentle boost in feed suggestions for members who have not joined them yet. Private and secret spaces can be featured too, but they only ever show to people who can already see them. See [Feature Spaces for New Members](../spaces/12-featured-spaces.md).

## What is the difference between removing a member from a space and banning them?

Removing takes them out of the roster but lets them rejoin or request to join again right away. Banning removes them and blocks every future join or join-request attempt until an owner or moderator unbans them. Use removal for routine cleanup and a ban for someone who should stay out. See [Space Bans](../spaces/06-space-bans.md).

## Can members create their own spaces?

Yes, subject to whatever your default plan (or a paid plan) allows - the "Spaces Created" perk on a membership plan sets how many spaces a member on that plan may create (0 means unlimited). With memberships off, the standard default limit applies to everyone. See [Membership Plans](../pro/01-membership-plans.md).

## Related

- [Spaces Overview](../spaces/01-spaces-overview.md)
- [Managing Space Members](../spaces/04-managing-members.md)
- [Gated Spaces](../pro/02-gated-spaces.md)
- [Membership Plans](../pro/01-membership-plans.md)

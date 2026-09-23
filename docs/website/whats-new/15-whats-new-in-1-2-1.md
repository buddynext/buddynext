# What's New in 1.2.1

This release is about steering new members to the right place, opening more ways to sell and share access, and tightening the seams between BuddyNext and the plugins it connects to - alongside a security hardening pass across secrets, private communities, and GDPR handling.

> **Note:** BuddyNext free and BuddyNext Pro are released together. If you run both, update them at the same time so they stay in step.

## Feature new spaces to new members

You can now choose which spaces to highlight instead of leaving discovery to a near-random list - useful on a young community where there are no member counts or activity yet to make an automatic "popular" list meaningful.

- Feature up to 6 spaces from **BuddyNext > Spaces > Directory**, and drag to set the order members see them in.
- Featured spaces lead the directory sidebar on desktop, show as a strip at the top on mobile, lead the "Spaces" step of new-member onboarding, and get a gentle boost in feed suggestions for members who have not joined them yet.
- Private and secret spaces can be featured too, but they only ever show to people who could already see them - a featured secret space never leaks to someone who shouldn't know it exists.

See [Feature Spaces for New Members](../spaces/12-featured-spaces.md).

## Shareable space invite links

Every space can now have one shareable invite link, instead of inviting people one at a time.

- Owners and moderators create the link with an expiry (1, 7, or 30 days, or never) and a use cap (1, 10, 100, or unlimited), and reset it any time to revoke the old one instantly - there is only ever one active link per space.
- It works for a signed-in visitor (join immediately, even into a private or secret space), a signed-out visitor (sign up or log in, then join automatically), and an existing member (it just opens the space).
- It is a shortcut in, not around your rules: a banned member is never let back in, and if your site requires email verification or a paid plan, the person still completes that step before landing in the space.
- Members who join this way are quietly flagged "Joined via invite link" on the space's Members tab, visible only to owners and moderators.

See [Invite people with a link](../spaces/11-invite-with-a-link.md).

## One plan, more spaces - and a paywall that says which ones qualify

Gating a space behind a membership plan now works both ways from either screen, so one plan can open several spaces and one space can be opened by several plans.

- Set the relationship from the space (**Monetization > Paywall**) or from the plan's own **Spaces this plan unlocks** field - both stay in sync.
- A plan carrying the **Gated Space Access** perk becomes an all-access pass into every gated space on the site, regardless of which specific plans each one lists.
- When a blocked member hits a gated space that more than one plan opens, the paywall names the qualifying plans cheapest-first, so they see the most affordable way in rather than a generic "upgrade" message.
- An administrator can put a member on any plan directly - the same door checkout uses - without them going through payment, recorded with the source **Manual** so it's clearly distinguishable in your Subscriptions report.

See [Gated Spaces](../pro/02-gated-spaces.md) and [Membership Plans](../pro/01-membership-plans.md).

## Smarter, more private profile fields

- **Conditional logic (Pro).** A field can now carry an "Only show this field when" rule tied to another field's answer - ask "Beard style" only when Gender is Male, or "Mentoring topics" only when "Open to mentoring" is ticked. It updates instantly, works the same at registration and on Edit Profile, and a hidden answer is cleared on save so it never lingers in search or the API.
- **Advanced number range (Pro).** The advanced number field type now enforces a unit, minimum, maximum, and step you configure, so a field meant for "years of experience" can't be answered with an out-of-range value.
- **Location privacy (Pro).** The Location field type publishes only the member's chosen **address** to other members, connected apps, and the API - never their exact coordinates. The precise point stays private to the member, used only to redraw their own map pin when they edit the field.
- A new **Some profile fields need attention** notice on the Profile Fields screen flags setup problems - an empty-options dropdown, a conditional field asked at signup whose deciding field isn't, or a condition that lost the field or answers it depends on - each with a **Fix** button that opens the right panel.

See [Custom Profile Fields](../members/02-profile-fields.md), [Conditional Logic for Profile Fields](../pro/27-conditional-profile-fields.md), and [Advanced Profile Field Types](../pro/09-advanced-profile-fields.md).

## Deeper Learnomy and Listora integration (Pro)

- **Learnomy community federation.** Link a course, a Learnomy Space, or a cohort to a BuddyNext Space, and membership now follows access automatically: enrolling adds the member to the linked community Space, and losing access - unenrolling, an expired enrollment, or leaving the Learnomy Space or cohort - removes them from it the same way. A Space BuddyNext creates for the link enforces the whole roster; a Space you already run only ever gains members through the link, never loses one you're managing yourself. The reverse also works: a membership plan can grant (and later withdraw) access to specific Learnomy courses or a Learnomy Space.
- **Listora business showcase in spaces.** A space owner can turn on a Businesses tab for their space; members submit one of their own published Listora listings, and the space team approves it before it appears as a compact card linking out to the full listing. Visibility follows the space's own privacy. Needs WB Listora 1.8.0 or newer.

See [Learnomy](../integrations/08-learnomy.md) and [Listora](../integrations/07-listora.md).

## Reversible integration cards, and live updates on posts you're viewing

- Integration feed cards from companion plugins (media, jobs, listings, and the rest) now follow the source content's own lifecycle: trashing or unpublishing withdraws the card, and restoring or re-publishing brings the exact same card back, rather than orphaning it or creating a duplicate.
- With BuddyNext Pro's real-time transport connected, reactions and comments on a post a member is currently viewing now update in place as they happen, alongside the already-live notification bell and feed.

See [Integration Bridges](../developer-guide/44-integration-bridges.md) and [Real-time WebSocket](../pro/18-realtime-websocket.md).

## Content warnings, and a wp-admin mirror of the moderation queue

- **Content warnings.** A moderator can tag a specific post NSFW, Spoilers, Violence, or Strong Language; the post stays up but blurs behind the label and a "Show anyway" button until a viewer chooses to reveal it. It's a targeted, moderator-applied cover for one already-reviewed post, not a member preference.
- **wp-admin moderation queue mirror.** The same reports, pending items, suspensions, and appeals a community moderator works from the front-end queue are also available to site administrators under **BuddyNext > Moderation** in wp-admin, with Escalate and Resolve available as row buttons there.

See [Content Warnings](../moderation/08-content-warnings.md) and [Moderation Queue](../moderation/02-moderation-queue.md).

## Admin quality-of-life

- **Restore defaults, per tab.** Any settings tab with resettable fields now carries a shared **Restore defaults** button, with a confirm dialog listing exactly what will change before you commit. Owner data - API keys, a banned-word list, uploaded images, page mappings - is never touched by a reset.
- **Integration health at a glance.** The Integration Settings screen shows a status badge per connected integration - **Active**, **Update needed** (the installed companion is below the bridge's supported floor), or **Newer partner** (the companion has moved ahead of what the bridge was last verified against) - so a bridge quietly falling behind a partner update is visible instead of silently drifting.

See [Admin Pages and Settings](../developer-guide/40-admin-pages-and-settings.md) and [Integration Bridges](../developer-guide/44-integration-bridges.md).

## Security hardening (Pro)

- **Provider secrets encrypted at rest.** Credentials such as the FCM service-account JSON, the realtime server secret, Stripe and PayPal secret keys, and the AI embedding key are now encrypted before they're written to the options table, so a plain database export no longer reveals them. Existing plaintext secrets are migrated automatically on upgrade.
- **Private Community access checked consistently.** The setting that puts your whole community behind a login screen is enforced the same way across every route that serves community pages and data, not just the ones a visitor browses directly by hand.

See [Provider secrets are encrypted at rest](../developer-guide/40-admin-pages-and-settings.md#provider-secrets-are-encrypted-at-rest-pro) and [Privacy and Data](../accounts-access/08-privacy-and-data.md).

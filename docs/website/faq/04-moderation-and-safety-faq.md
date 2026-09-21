# Moderation and Safety FAQ

Questions about how BuddyNext keeps a community safe: reporting, reviewing, content warnings, and account security.

## Does BuddyNext hold every post for approval before it goes live?

No. BuddyNext moderates reactively: members post freely, and moderation happens through reporting, review, and removal after the fact - not by gating every post behind pre-approval. (A small, optional Pending queue exists for specific automatic safeguards; it is empty on almost every site. See [Content Safeguards](../moderation/05-content-safeguards.md).)

## What happens when I report something?

Your report is added to the moderation queue as a pending item. The person you reported is not notified, and your identity is never shown to them. Nothing is hidden or removed just because you reported it - a moderator reviews the report and decides whether to dismiss it, remove the content, or act on the member. See [Reporting Content](../moderation/01-reporting-content.md).

## Can I report the same post more than once, or does reporting it get it removed faster?

Each member can report a given item once - a second attempt is blocked. Reports from different members on the same item are grouped together, and the combined count raises the item's urgency in the queue (three or more reports marks it Urgent). Once an item reaches the Auto-Hide Threshold (five distinct reports by default), it is automatically put "Under review" and hidden from other members while a moderator decides - nothing is deleted at that point. See [Reporting Content](../moderation/01-reporting-content.md).

## Are photo and video reports handled the same way as post reports?

No. Reports on photos and videos go to WPMediaVerse's own **Media Moderation** queue (WPMediaVerse > Media Moderation in wp-admin), because that plugin owns the media, with its own media-specific reasons. Reports on posts, comments, messages, and profiles go to BuddyNext's own Moderation queue. If you moderate media on your community, check both. See [Reporting Content](../moderation/01-reporting-content.md).

## What is a content warning, and who can add one?

A content warning is a moderator-applied tag (NSFW, Spoilers, Violence, or Strong Language) that blurs a specific post behind a label until a viewer chooses to reveal it. It is not something the post's author sets, and it is not a personal filter a viewer configures for themselves - a moderator applies it to one post they have already reviewed, the same way they would apply a strike or a removal. See [Content Warnings](../moderation/08-content-warnings.md).

## What is the difference between a warning, a strike, a suspension, and a shadow-ban?

They escalate from lightest to heaviest:

- **Warning** - a formal, recorded notice. Nothing is restricted.
- **Strike** - a counted mark against the member. Reaching a threshold escalates automatically to a warning email, then a suspension, then (if the owner has opted in) a permanent ban.
- **Suspension** - blocks the member from posting for a set period or indefinitely; their existing content stays visible and they can still read the community.
- **Shadow-ban** - silently hides the member's content from everyone else. The member sees their own posts normally and is not told, which is what makes it effective against repeat spammers who would just create a new account if openly banned.

See [Moderating a Member](../moderation/03-user-moderation.md).

## Can a suspended member dispute the decision?

Yes, through an appeal. The member submits a written explanation tied to the specific suspension, and a moderator approves it (which lifts that exact suspension immediately) or denies it. Appeals are private between the member and moderators. See [Appeals](../moderation/04-appeals.md).

## Does BuddyNext use a third-party captcha to stop spam sign-ups?

No. Sign-up spam protection is built in - it quietly screens out bots and fake sign-ups, with an optional simple math question ("what is three plus five?") as a human check. There is no external captcha service to set up or pay for, and genuine members are never shown extra friction unless a submission looks suspicious. See [Registration](../accounts-access/01-registration.md).

## Is two-factor authentication required?

Not by default - 2FA is opt-in, and each member decides for their own account. An owner can require it for specific roles (Administrators, Administrators and editors, or Everyone) under Members > Registration & Login. A member in a required role is held on the account setup screen until they add a second factor, but their sign-in itself still works - they are not locked out. See [Two-Factor Authentication](../accounts-access/05-two-factor-authentication.md).

## Related

- [Reporting Content](../moderation/01-reporting-content.md)
- [Moderation Queue](../moderation/02-moderation-queue.md)
- [Moderating a Member](../moderation/03-user-moderation.md)
- [Content Warnings](../moderation/08-content-warnings.md)

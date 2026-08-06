# What's New in 1.1.2

BuddyNext 1.1.2 is a fix release, and the fixes are ones members feel. Pasting a link into the composer could leave it stuck on "Posting..." with no way back, and the preview card it was supposed to show never appeared at all. Both turned out to be the same root cause. A post permalink opened with its entire comment thread hidden. Member cards ignored the cover photo a member had uploaded. Two privacy holes are closed.

> **Note:** BuddyNext free and BuddyNext Pro are released together. If you run both, update them at the same time so they stay in step.

## Link posts work

Two symptoms, one cause.

Posting a link could hang. The composer sat on "Posting..." with the text box disabled, sometimes for a minute, sometimes apparently forever. Text posts and polls were unaffected, which made it look like a problem with links specifically - and it was, but not for the reason it appeared.

Separately, the preview card never showed while composing. Paste a link, wait, nothing. Since the card usually appeared on the finished post, this read as cosmetic.

Both came from a single call in the safety check that stops the server fetching internal addresses. That check resolved the link's hostname with a function that has no timeout you can set, and on many hosting setups it blocked for a full 60 seconds every time. Everything downstream inherited that wait: the preview request, and - because saving a post ran the same check - the post itself.

The hostname is now resolved through the system's own resolver, which is what every other part of the stack already used. The safety check is unchanged; only the lookup behind it moved.

Two things follow from that:

- **The preview appears while you type.** Paste a link, and the card fills in about a second later, the way it does on other networks.
- **Saving a post never waits on the linked site.** If a link is slow, unreachable or offline, the post still publishes immediately and the preview is fetched in the background, appearing when it arrives. A dead link costs you a plain link card, never your post.

Link cards also lost the first letters of their domain - `wbcomdesigns.com` displayed as `bcomdesigns.com`, `wordpress.org` would have shown as `ordpress.org`. Any domain starting with a `w` was affected.

## A post permalink opens with its conversation

Opening a single post at its own address showed the post and nothing else. The comments were there, but hidden behind the Comment button, on the one page whose entire purpose is the conversation.

This mattered most where it was least obvious. "Someone commented on your post" notifications link straight to that address, so following one landed you on a page with no comments visible and left you hunting for the reply you had just been told about.

A permalink now opens with the thread expanded. Long threads page exactly as they do in the feed, and the Comment button still collapses and reopens the thread.

## Member cards show real cover photos

The member directory drew a coloured gradient on every card, including for members who had uploaded a cover photo - which showed correctly on their profile. The card was always able to display it; the directory simply never sent it. Members with no cover still get the gradient.

## Media and the composer

- **Real previews before you post.** Images, video and audio each show what you are actually about to post, instead of a generic tile.
- **Explore renders typed media cards.** Audio and video get their own card shape with a real video poster, rather than a broken image frame.
- **The feed loads past 50 posts.**
- **A failed embed no longer blanks the feed around it.**
- **Popovers stay on screen at phone widths** - the audience picker, the comment reaction picker and the composer's touch targets.

## Profiles and members

- A long bio collapses behind **Show more** instead of pushing the rest of the profile down the page.
- Members can no longer overwrite a member type the community assigned them, and a profile saves even when the member type field is not submittable.
- Notification and digest frequency settings save the value you chose.
- A zero-result search no longer leaves the unfiltered pager sitting under the empty state.

## Privacy and security

Two fixes here, both worth reading if you run a private community.

**Discussions on private spaces were world-readable.** Turning on Discussion for a private or secret space created the discussion as public, whatever the space was. The conversation was then visible to anyone, and nothing re-evaluated it afterwards. New discussions now take their space's privacy.

Existing discussions are not changed automatically, because rewriting visibility in bulk is not something an update should do silently. Realign them when you are ready:

```
wp buddynext repair-discussion-visibility --dry-run
wp buddynext repair-discussion-visibility
```

Run the dry run first: it lists what would change without changing anything.

**Logged-out visitors were granted every capability** by a permission check that returned early before evaluating anything.

## Also fixed

- Pressing `n` reloaded the page instead of focusing the composer already on it.
- Offline mode no longer answers API requests with the offline page.
- Turning on plugin isolation no longer silently deletes navigation menu items.
- A one-to-one conversation with nobody on the other end renders instead of erroring.
- Six REST defects found in mobile-app triage, covering fields the app reads on the profile and connection blocks.
- German ships complete, and the shipped-languages claim is now enforced by the build.

## For developers

- The REST catalogue takes its version from the plugin, so the published specification can no longer lag a release.
- A new action, `buddynext_post_link_meta_resolved`, fires when a queued link preview resolves and is stored on the post. Anything that renders or caches a link card can listen for the late arrival.
